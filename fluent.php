<?php
/*
Plugin Name: Download Fluent Conference Videos
Description: WP-CLI script for downloading videos of the Fluent 2013 conference and embedding them into posts.
Author: Gabriel Koen, PMC
Version: 1.0
*/

if ( ! defined( 'WP_CLI' ) || ( defined( 'WP_CLI' ) && ! WP_CLI ) ) {
	return;
}

require __DIR__ . '/aws-sdk-php-master/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Enum\CannedAcl;
use Aws\S3\Model\MultipartUpload\UploadBuilder;
use Aws\S3\Model\MultipartUpload\AbstractTransfer;
use Guzzle\Http\Client;
use Guzzle\Http\EntityBody;

class PMC_Fluent extends WP_CLI_Command {
	protected $_s3_client = null;
	protected $_s3_bucket_name = 'example.com';
	protected $_s3_object_path = '/fluent-2013/';

	protected function _s3_connect() {
		$this->_s3_client = S3Client::factory( array(
			'key' => '',
			'secret' => '',
		) );
	}

	protected function _maybe_upload_object( $source_path, $desired_filename = false ) {

		if ( ! $desired_filename ) {
			$desired_filename = pathinfo( $source_path, PATHINFO_BASENAME );
		}

		$desired_filename_parts = pathinfo( $desired_filename );

		if ( ! isset($desired_filename_parts['extension']) ) {
			$source_extension = pathinfo( $source_path, PATHINFO_EXTENSION );
			if ( $source_extension ) {
				$desired_filename_parts['extension'] = $source_extension;
			}
		}

		$key = sanitize_title_with_dashes( $desired_filename_parts['filename'], null, 'save' );

		if ( isset($desired_filename_parts['extension']) ) {
			$key .= '.' . sanitize_title_with_dashes( $desired_filename_parts['extension'], null, 'save' );
		}

		$key = $this->_s3_object_path . $key;

		WP_CLI::line( 'Uploading ' . $source_path . ' to ' . $this->_s3_bucket_name . '/' . $key );

		WP_CLI::line( 'Determining whether upload is needed...' );

		$object_exists = $this->_s3_client->doesObjectExist( $this->_s3_bucket_name, $key );

		if ( $object_exists ) {
			WP_CLI::line( $key . ': Object already exists in bucket ' . $this->_s3_bucket_name . '.  Nothing to do.' );
			return 'http://' . $this->_s3_bucket_name . '/' . $key;
		}

		$is_url = (bool) parse_url( $source_path, PHP_URL_SCHEME );

		if ( $is_url ) {
			$tmp_dir = trailingslashit( sys_get_temp_dir() );

			$destination_path = $tmp_dir . $desired_filename_parts['filename'];

			WP_CLI::line( $key . ': Downloading a local copy of the object...' );

			if ( file_exists( $destination_path ) ) {
				WP_CLI::line( $key . ': Temporary file exists from previous session, nothing to download.' );
			} else {
				$destination_handle = fopen( $destination_path, 'w+' );

				$client = new Client( $source_path );

				$response = $client->get( null, null, array( 'save_to' => $destination_handle ) )->send();

				fclose( $destination_handle );

				WP_CLI::line( $key . ': Download complete.' );
			}

			$source_path = $destination_path;

		}

		WP_CLI::line( $key . ': Uploading to S3...' );

		$unlink_downloaded_file = true;
		try {
			$this->_s3_client->upload( $this->_s3_bucket_name, $key, $source_path, CannedAcl::PUBLIC_READ );
		} catch(CurlException $e ) {
			$unlink_downloaded_file = false;
		}
		if ( isset($destination_path) && $unlink_downloaded_file ) {
			unlink( $destination_path );
		}

		WP_CLI::line( $key . ': Upload complete.' );

		return 'http://' . $this->_s3_bucket_name . '/' . $key;
	}

	/**
	 * @subcommand import
	 */
	public function _import( $args, $assoc_args ) {
		if ( ! isset( $assoc_args['file'] ) ) {
			WP_CLI::error( '--file is required' );
		}

		$filename = $assoc_args['file'];
		if ( strpos( '/', $filename ) !== 0 || strpos( './', $filename ) !== 0 ) {
			$filename = './' . $filename;
		}
		$filename = realpath( $filename );
		if ( ! $filename || ! file_exists( $filename ) ) {
			WP_CLI::error( $filename . ' does not exist.' );
		}

		$fluent_parent_cat_id = wp_create_category( 'Training' );
		$fluent_cat_id = wp_create_category( 'Fluent 2013', $fluent_parent_cat_id );

		ini_set('auto_detect_line_endings', true);

		$fhandle = fopen( $filename, 'r' );
		$section = null;

		$this->_s3_connect();

		while( ( $data = fgetcsv( $fhandle ) ) !== false ) {
			$data = array_filter($data);
			$data = array_map( 'trim', $data );
			$count = count( $data );
			if ( $count === 1 && ! empty($data[0]) ) {
				$section = $data[0];
				$fluent_section_cat_id = wp_create_category( $section, $fluent_cat_id );
				WP_CLI::line( 'New section: ' . $section );
				continue;
			}

			$post_data = array(
				'post_title' => $data[1],
				'post_content' => $data[2],
				'post_name' => sanitize_title_with_dashes( $data[1], null, 'save' ),
				'post_status' => 'publish',
				'post_category' => array( $fluent_parent_cat_id, $fluent_cat_id, $fluent_section_cat_id ),
			);

			WP_CLI::line( 'Processing ' . $post_data['post_title'] );

			$posts = get_posts( array(
				'posts_per_page' => 1,
				'suppress_filters' => true,
				'name' => $post_data['post_name'],
			) );

			if ( isset( $posts[0]->ID ) ) {
				WP_CLI::line( 'Post already exists.  Post will be updated.  ID: ' . $posts[0]->ID );
				$post_data['ID'] = $posts[0]->ID;
			}

			$video_url = $this->_maybe_upload_object( $data[5], $post_data['post_name'] );

			$ratio = array( 16, 9 );
			$width = 600;
			$height = round( ( intval($ratio[1]) * intval($width) ) / intval($ratio[0]) );

			$post_data['post_content'] .= "\n\n" . '[video width="' . $width . '" height="' . $height . '" src="' . $video_url . '"]';

			$post_data['post_content'] .= "\n\n" . 'Direct URL: <a href="' . $video_url . '">' . $video_url . '</a>';

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				WP_CLI::error( 'Error inserting post' );
			}

			if ( strpos( '- Part ', $post_data['post_title'] ) !== false ) {
				$title_parts = explode( ' - ', $post_data['post_title'] );
				wp_set_post_terms( $post_id, array( $title_parts[0] ), 'post_tag' );
			}

			update_post_meta( $post_id, 'fluent_video_duration', $data[3] );
			update_post_meta( $post_id, 'fluent_video_size', $data[4] );
			update_post_meta( $post_id, 'fluent_video_source_url', $data[5] );
			update_post_meta( $post_id, 'fluent_video_url', $video_url );
		}
		fclose( $fhandle );

		WP_CLI::success( 'Done!' );
	}
}

WP_CLI::add_command( 'fluent', 'PMC_Fluent' );