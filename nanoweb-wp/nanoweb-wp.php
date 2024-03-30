<?php

/**
 * Plugin Name: Nan•web synchronizer
 * Plugin URI: https://github.com/yaro-rasta/nanoweb-wp-plugin
 * Description: Exports all posts from a multisite network to individual txt files.
 * Version: 1.0
 * Author: yaro.page
 * Author URI: https://yaro.page
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Network: true
 */

include_once(__DIR__ . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'fs.php');

add_action('admin_menu', 'nw_menu');

function nw_menu()
{
  add_menu_page(
    'Nan•web synchronizer',
    'Nan•web synchronizer',
    'manage_options',
    'nw-export-posts',
    'nw_export_posts'
  );
}

function nw_export_posts()
{
  $message = '';

  if (isset($_POST['export_posts'])) {
    $message = nw_export_all_posts();
  }
?>
  <h1>Nan•web synchronizer</h1>
  <?php if (!empty($message)) : ?>
    <div id="message" class="updated fade">
      <?php echo $message; ?>
    </div>
  <?php endif; ?>
  <p>This plugin will export all posts from all blogs in your multisite network to individual txt files. Each file will contain the post title, featured image URL (if available), and content.</p>
  <form method="post">
    <p class="submit">
      <input type="submit" name="export_posts" class="button-primary" value="Export Posts">
    </p>
  </form>
<?php
}

function nw_get_post($post = null)
{
  $data = [];
  $data['id']      = $post->ID;
  $data['url']     = get_permalink($post);
  $data['mtime']   = get_the_modified_date('c', $post);
  $data['ptime']   = get_the_date('c', $post);
  $data['title']   = get_the_title($post);
  $data['image']   = get_the_post_thumbnail_url($post);
  $data['content'] = get_the_content(null, false, $post);
  $data['author']  = get_the_author();
  // Get post tags
  $tags = get_the_tags($post);
  if ($tags) {
    $data['tags'] = wp_list_pluck($tags, 'name');
  } else {
    $data['tags'] = [];
  }
  // Get post categories
  $categories = get_the_category($post);
  if ($categories) {
    $data['cats'] = wp_list_pluck($categories, 'name');
  } else {
    $data['cats'] = [];
  }
  return $data;
}

function nw_posts_from_blog($site, $posts)
{
  switch_to_blog($site->blog_id);
  $locale = nw_get_locale();
  $posts[$locale] = [];

  // Query posts from the current site
  $args = array(
    'post_type'      => 'post',
    'posts_per_page' => -1, // Retrieve all posts
    'post_status'    => 'publish', // Retrieve only published posts
  );
  $query = new WP_Query($args);

  // Loop through posts and export data to text files
  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $data = nw_get_post();
      $year = substr($data['ptime'], 0, 4);
      if (empty($posts[$locale][$year])) $posts[$locale][$year] = [];
      $posts[$locale][$year][$data['url']] = $data;
    }
  }
  restore_current_blog();
}

function nw_export_all_posts()
{
  $message = [];

  $sites = array_filter(get_sites(), function ($site) {
    return intval($site->archived) + intval($site->deleted) === 0;
  });
  $posts = [];
  foreach ($sites as $site) nw_posts_from_blog($site, $posts);
  $meta = nw_write_sites($posts);

  $message[] = "Export completed.";
  $message[] = "Files written:";
  $table = [];
  $table[] = "<table><thead><tr><th>Locale</th><th>Year</th><th>Posts</th><th>File</th></tr></thead><tbody>";
  foreach ($posts as $locale => $arr) {
    foreach ($arr as $year => $posts) {
      $table[] = "<tr>";
      $table[] = "<td>" . htmlspecialchars($locale) . "</td>";
      $table[] = "<td>" . htmlspecialchars($year) . "</td>";
      $table[] = "<td>" . htmlspecialchars(count($posts)) . "</td>";
      $table[] = "<td>" . htmlspecialchars($meta[$locale][$year]) . "</td>";
      $table[] = "</tr>";
    }
  }
  $table[] = '</tbody></table>';
  $message[] = implode('', $table);
  return implode('<br>', $message);
}

function nw_export_post($post)
{
  switch_to_blog($post->blog_id);
  $meta = nw_read_sites();
  $locale = nw_get_locale();
  $data = nw_get_post($post);
  $year = substr($data['ptime'], 0, 4);
  if (!isset($meta[$locale])) $meta[$locale] = [];
  $posts = [];
  if (empty($meta[$locale][$year])) {
    $meta[$locale][$year] = nw_get_site_json_file($locale, $year);
  } else {
    $posts = nw_read_site($meta[$locale][$year]);
  }
  $posts[$data['url']] = $data;
  $res = nw_write_site($meta[$locale][$year], $posts);
  if (!$res) {
    if (!isset($meta['errors'])) $meta['errors'] = [];
    $meta['errors'][$locale] = $year;
  }
  nw_write_meta($meta);
  restore_current_blog();
}

function nw_remove_post($post)
{
  switch_to_blog($post->blog_id);
  $meta = nw_read_sites();
  $locale = nw_get_locale();
  $data = nw_get_post($post); // Assuming this function retrieves post data
  $year = substr($data['ptime'], 0, 4);

  if (!isset($meta[$locale]) || empty($meta[$locale][$year])) {
    // Post not found in any files, no action needed
    restore_current_blog();
    return;
  }

  $posts = nw_read_site($meta[$locale][$year]);

  // Remove the post from the file
  if (!isset($posts[$data['url']])) {
    restore_current_blog();
    return;
  }
  unset($posts[$data['url']]);

  // Write the updated posts back to the file
  nw_write_site($meta[$locale][$year], $posts);

  // If the file is now empty, remove it and its entry from the meta
  if (empty($posts)) {
    unlink($meta[$locale][$year]);
    unset($meta[$locale][$year]);
  }

  // Update the meta with any changes
  nw_write_meta($meta);

  restore_current_blog();
}

add_action('save_post', 'nw_export_post_on_save', 10, 2);

function nw_export_post_on_save($post_id, $postdata)
{

  // Check if this is an autosave
  if (wp_is_post_autosave($post_id)) {
    return;
  }

  // Check if post is published
  $post = get_post($post_id);
  if ($post->post_status !== 'publish') {
    nw_remove_post($post);
    return;
  }

  // Now call your actual export function with the post object
  nw_export_post($post);
}

add_action('before_delete_post', 'nw_remove_post_on_delete', 10, 1);

function nw_remove_post_on_delete($post_id)
{
  $post = get_post($post_id);
  if (!$post) {
    return;
  }

  // Call your function to remove the post from export files
  nw_remove_post($post);
}
