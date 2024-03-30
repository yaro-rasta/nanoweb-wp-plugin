<?php

function nw_get_locale()
{
    $lang = get_option( 'WPLANG' );
    if (!$lang) $lang = 'en-US';
    return substr($lang, 0, 2);
}

function wp_ensure_dir($file)
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function nw_get_upload_dir()
{
    return WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'nanoweb';
}

function nw_get_site_json_file($locale, $year)
{
    return nw_get_upload_dir() . DIRECTORY_SEPARATOR . $locale . '-' . $year . '.json.txt';
}

function nw_get_sites_file()
{
    $dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'nanoweb';
    return $dir . DIRECTORY_SEPARATOR . 'all.json';
}

function nw_read_sites()
{
    $file = nw_get_sites_file();
    if (!file_exists($file)) return [];
    $meta = json_decode(file_get_contents($file), true);
    foreach ($meta as $locale => $arr) {
        if ('errors' === $locale) continue;
        foreach ($arr as $year => $file) {
            $meta[$locale][$year] = nw_get_site_json_file($locale, $year);
        }
    }
    return $meta;
}

function nw_write_meta($meta)
{
    $data = [];
    foreach ($meta as $locale => $arr) {
        if ('errors' === $locale) continue;
        $data[$locale] = [];
        foreach ($arr as $year => $file) {
            $data[$locale][$year] = basename($file);
        }
    }
    if (isset($meta['errors'])) $data['errors'] = $meta['errors'];
    $file = nw_get_sites_file();
    wp_ensure_dir($file);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function nw_write_sites($data)
{
    $meta = [];
    foreach ($data as $locale => $arr) {
        $meta[$locale] = [];
        foreach ($arr as $year => $posts) {
            $meta[$locale][$year] = nw_get_site_json_file($locale, $year);
            $res = nw_write_site($meta[$locale][$year], $posts);
            if (!$res) {
                if (!isset($meta['errors'])) $meta['errors'] = [];
                $meta['errors'][$locale] = $year;
            }
        }
    }
    nw_write_meta($meta);
    return $meta;
}

function nw_write_site($file, $posts)
{
    wp_ensure_dir($file);
    $fp = fopen($file, 'w+');
    if (!$fp) return false;
    foreach ($posts as $post) {
        fwrite($fp, json_encode($post, JSON_UNESCAPED_UNICODE));
        fwrite($fp, "\n");
    }
    fclose($fp);
    return true;
}

function nw_read_site( $file )
{
    if (!file_exists($file)) return [];
    $lines = file($file);
    $posts = [];
    foreach ($lines as $line) {
        $item = json_decode($line, true);
        if (!$item) continue;
        $posts[$item['url']] = $item;
    }
    return $posts;
}