<?php
/*
Plugin Name: MFS3 
Plugin URI: http://magicfields.org
Description: This plugin provide a integration between magic fields and Amazon S3 ( Using the tantan plugin) 
Version: 1
Author: Magic Fields Team
Author URI: http://magicfields.org
Licence: GPL2
*/
global $mfs3_prefix;

if(isset($current_blog)){
  $mfs3_prefix=$wpdb->base_prefix;
}else{
  $mfs3_prefix=$wpdb->prefix;
}

/**
 * Magic Fields and WPML is already installed?
 *
 */
add_action('init','checking_dependencies');
function checking_dependencies() {

  /**
   */
  if(! method_exists('PanelFields','PanelFields') || ! class_exists('TanTanWordPressS3Plugin')) {
    add_action('admin_notices','mfs3_notices');
  }
}

/**
 * Display a message in function if all the dependencies of the plugins
 * are installed or not
 *
 *
 */
function mfs3_notices() {
  echo "<div class=\"mf_message error\">You need install first Magic Fields and TanTan S3 Plugin for use this plugin (mfs3 use the same configuration of the Tantan plugin)</div>";
}

//Install
register_activation_hook(__FILE__,'mfs3_install');
function mfs3_install(){
  global $wpdb,$mfs3_prefix;
  $table_name = $mfs3_prefix.'mfs3_images';

  $sql = "CREATE TABLE ". $table_name . " ( 
    id int NOT NULL AUTO_INCREMENT,
    bucket varchar(200) NOT NULL,
    image_path varchar(255) NOT NULL,
    UNIQUE KEY id (id)
    );";

  require_once(ABSPATH. 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}

add_action('mf_after_upload_file','mfs3_after_upload');
function mfs3_after_upload($file) {

  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');

  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

    $s3 = new TanTanS3($s3_options['key'], $s3_options['secret']);
    $s3->setOptions($s3_options);

    //getting the name of the file
    preg_match('/wp\-content\/.+/',$file['tmp_name'],$match);
    $file_name = $match[0];

    $s3->putObjectStream($s3_options['bucket'], $file_name, $file);
}

add_action('mf_before_delete_file','mfs3_before_delete');
function mfs3_before_delete($file_name) {
  global $wpdb,$mfs3_prefix;

  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');

  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  $s3 = new TanTanS3($s3_options['key'], $s3_options['secret']);
  $s3->setOptions($s3_options);

  $file = 'wp-content/files_mf/'.$file_name;

  $query = "DELETE FROM ".$mfs3_prefix."mfs3_images WHERE bucket = '".$s3_options['bucket']."' AND image_path = '".$file."'";
  $wpdb->query($query);
  $s3->deleteObject($s3_options['bucket'], $file);
}

/**
 * Saving the MF image
 */ 
add_action('mf_presave','mfs3_save_image',10,7);
function  mfs3_save_image($field_meta_id,$name,$group_index,$field_index,$post_id,$value_field,$writepanel_id){
  global $wpdb,$FIELD_TYPES,$mfs3_prefix, $blog_id,$current_blog;

  if($value_field == "") {
    return false;
  }

  //Loading the documentation of the tantan plugin
  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');
  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  //S3 Prefix
  $prefix = 'wp-content/files_mf/';
  if(isset($current_blog)){
    $prefix =  'wp-content/blogs.dir/'.$blog_id.'/files_mf/';
  }

  //checking if the custom fields is a image
  $type = $wpdb->get_var("
    SELECT 
      sf.type  
    FROM 
      ".MF_TABLE_GROUP_FIELDS." as sf 
    LEFT JOIN 
      ".MF_TABLE_PANEL_GROUPS." as g 
    ON 
      (sf.group_id = g.id ) 
    WHERE 
      panel_id = ".$writepanel_id."
    AND 
      sf.name = '".$name."'"
  );

  if( $type == $FIELD_TYPES['image'] || $type == $FIELD_TYPES['file'] || $type == $FIELD_TYPES['audio'])  {
    //Saving in the database a reference to this image
    $wpdb->query("INSERT INTO ".$mfs3_prefix."mfs3_images VALUES ('','".$s3_options['bucket']."','".$prefix.$value_field."')");
  }
}

add_filter('mf_source_image', 'mfs3_source_image');
function mfs3_source_image($image_source){
  global $wpdb,$mfs3_prefix;

  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  //getting the name of the file
  preg_match('/wp\-content\/.+/',$image_source,$match);
  $file = $match[0];

  //checking if the image is in the CDN
  $query = 'SELECT COUNT(1) FROM '.$mfs3_prefix.'mfs3_images WHERE bucket = "'.$s3_options['bucket'].'" AND image_path = "'.$file.'"';
  $exists = $wpdb->get_var($query);

  if($exists) {
    $accessDomain = $s3_options['bucket'].'.s3.amazonaws.com';
    return 'http://'.$accessDomain.'/'.$file;
  }
  return $image_source;
}

add_filter('mf_source_path_thumb_image', 'mfs3_source_thumb_image');
function mfs3_source_thumb_image($params = array()){
  global $wpdb,$mfs3_prefix;
  
  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  //getting the name of the file
  preg_match('/wp\-content\/.+/',$params['thumb_url'],$match);
  $file = $match[0];

  //checking if the image is in the CDN
  $query = 'SELECT COUNT(1) FROM '.$mfs3_prefix.'mfs3_images WHERE bucket = "'.$s3_options['bucket'].'" AND image_path = "'.$file.'"';
  $exists = $wpdb->get_var($query);

  if($exists) {
    $accessDomain = $s3_options['bucket'].'.s3.amazonaws.com';
    return array(true,'http://'.$accessDomain.'/'.$file);
  }
  return array(false,$params['thumb_url']);
}

add_action('mf_before_generate_thumb','before_generate_thumb'); 
function before_generate_thumb($image_path){

  //before to trying to download the file we are check if is in the local machine
  if(file_exists($image_path)) {
    return true;
  }


  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  preg_match('/(.+\/files_mf\/)(.+)/',$image_path,$match);
  preg_match('/wp\-content\/.+/',$image_path,$match2);


  $path = $match[1];
  $image_name = $match[2];
  $remote_file= $match2[0];
    
  $accessDomain = $s3_options['bucket'].'.s3.amazonaws.com';
  $remote_file =  'http://'.$accessDomain.'/'.$remote_file;

  $ch = curl_init();
           
  curl_setopt($ch, CURLOPT_URL, $remote_file);

  $fp = fopen($image_path, 'w');

  curl_setopt($ch, CURLOPT_FILE,$fp);
  curl_exec($ch);
  curl_close($ch);
  fclose($fp);
}


add_action('mf_save_thumb_file','mfs3_save_thumb_file');
function mfs3_save_thumb_file($filename){
  global $wpdb,$FIELD_TYPES,$mfs3_prefix;

  if($filename == "") {
    return false;
  }

  //Loading the documentation of the tantan plugin
  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');
  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  preg_match('/wp-content\/.+/',$filename,$match);

  $file = $match[0];
  $exists = $wpdb->get_var("SELECT count(1) FROM ".$mfs3_prefix."mfs3_images WHERE bucket = '".$s3_options['bucket']."' AND image_path = '".$file."'");


  if(!$exists) {
    //Saving in the database a reference to this image
    $wpdb->query("INSERT INTO ".$mfs3_prefix."mfs3_images VALUES ('','".$s3_options['bucket']."','".$file."')");
  }
}
