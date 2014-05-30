<?php
/**
 * Spip XML file parser implementations
 */

/**
 * Spip Importer class for managing parsing of Spip XML files.
 */
class Spip_XML_Parser
{
  function parse( $file )
  {
    if ( extension_loaded( 'simplexml' ) ) {
      $parser = new Spip_XML_Parser_SimpleXML;
      $result = $parser->parse( $file );
      // If SimpleXML succeeds or this is an invalid Spip XML file then return the results
      if ( !is_wp_error( $result ) || 'SimpleXML_parse_error' != $result->get_error_code() )
        return $result;
    } else {
      echo '<p><strong>' . __( 'There was an error when reading this Spip XML file', 'wordpress-importer' ) . '</strong> : Simple XML is not loaded.</p>';
    }
  }
}

/**
 * Spip XML Parser that makes use of the SimpleXML PHP extension.
 */
class Spip_XML_Parser_SimpleXML
{
  public function parse( $file )
  {
    $internal_errors = libxml_use_internal_errors( true );
    $dom             = new DOMDocument;
    $old_value       = null;
    if ( function_exists( 'libxml_disable_entity_loader' ) ) {
      $old_value = libxml_disable_entity_loader( true );
    }
    $success = $dom->loadXML( file_get_contents( $file ) );
    if ( !is_null( $old_value ) ) {
      libxml_disable_entity_loader( $old_value );
    }
    if ( !$success || isset( $dom->doctype ) ) {
      return new WP_Error( 'SimpleXML_parse_error', __( 'There was an error when reading this Spip XML file', 'wordpress-importer' ), libxml_get_errors() );
    }
    $xml = simplexml_import_dom( $dom );
    unset( $dom );
    // halt if loading produces an error
    if ( !$xml ) {
      return new WP_Error( 'SimpleXML_parse_error', __( 'There was an error when reading this Spip XML file', 'wordpress-importer' ), libxml_get_errors() );
    }
    return self::get_data( $xml );
  }

  private static function get_post( $object )
  {
    $name = self::format_title( ( string ) $object->titre );
    $date = ( string ) $object->date;
    $maj  = ( string ) $object->maj;
    return array(
      'post_title' => trim( $name['title'] ),
      'post_date' => $date,
      'post_date_gmt' => $date,
      'post_name' => sanitize_title( $name['title'] ),
      'menu_order' => $name['menu_order'],
      'post_modified' => $maj,
      'post_modified_gmt' => $maj,
      'postmeta' => array(),
      'post_parent' => 0,
      'status' => 'publish',
      'is_sticky' => 0,
      'post_author' => 0,
      'post_excerpt' => '',
      'comment_status' => '',
      'ping_status' => '',
      'guid' => '',
      'post_password' => ''
    );
  }

  private static function format_post_content( $content )
  {
    // Lists are defined by items like "*-" : work on that later
    $content = preg_replace( '/\[([^->]*?)\]/is', '<em>($1)</em>', $content );
    $content = str_replace( '{{{', '<h3>', $content );
    $content = str_replace( '}}}', '</h3>', $content );
    $content = str_replace( '{{', '<b>', $content );
    $content = str_replace( '}}', '</b>', $content );
    $content = str_replace( '{', '<em>', $content );
    $content = str_replace( '}', '</em>', $content );
    $content = str_replace( '[[', '(<em>', $content );
    $content = str_replace( ']]', '</em>)', $content );
    $content = str_replace( '<quote>', '<blockquote>', $content );
    $content = str_replace( '</quote>', '</blockquote>', $content );
    $content = preg_replace( '/\[(.*?)->(.*?)\]/is', '<a href="$2">$1</a>', $content );
    return trim( $content );
  }

  private static $documents_urls = array();
  // Will replace the images tags from posts content
  // preg_replace_callback( '/\<img([0-9]*)\|?(left|center|right)?\>/is', ...
  private static function replace_image_tag( $matches )
  {
    $id_document = $matches[1];
    $class       = empty( $matches[2] ) ? '' : ' class="align' . $matches[2] . '"';
    return '<img src="' . $documents_urls[$id_document] . '"' . $class . ' />';
  }
  // For a next version : what about  <doc> and <emb> ?

  private static function format_title( $title )
  {
    // Objects are ordered by a number in title... eventually
    if ( preg_match( '/^([0-9]+)\.(.+)/', $title, $matches ) ) {
      return array(
        'title' => $matches[2],
        'menu_order' => $matches[1]
      );
    }
    return array(
      'title' => $title,
      'menu_order' => '0'
    );
  }

  private static $logos_url;
  private static function get_logo_url( $id_article )
  {
    $path = self::$logos_url . 'arton' . $id_article;
    $urls = array(
      $path . '.jpg',
      $path . '.png',
      $path . '.gif'
    );
    foreach ( $urls as $url ) {
      $curl = curl_init( $url );
      curl_setopt( $curl, CURLOPT_NOBODY, true );
      $result = curl_exec( $curl );
      $status = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
      curl_close( $curl );
      if ( $result && 200 === $status ) {
        return $url;
      }
    }
    return false;
  }

  private static function get_data( $xml )
  {
    $authors = $posts = $categories = $tags = $terms = array();

    // Record IDs for terms and posts, so we don't override "rubriques" by "tags" (terms), and "articles" by "documents" (posts).
    $term_ids = $post_ids = array();

    // URLS
    $url             = ( string ) $xml->attributes()->adresse_site;
    $base_url        = trailingslashit( trim( $url ) );
    $url             = ( string ) $xml->attributes()->dir_img;
    $documents_url   = trailingslashit( $base_url . ltrim( $url, '/.' ) );
    $url             = ( string ) $xml->attributes()->dir_logos;
    self::$logos_url = trailingslashit( $base_url . ltrim( $url, '/.' ) );

    // AUTHORS
    $auteurs = array();
    if ( property_exists( $xml, 'spip_auteurs' ) ) {
      foreach ( $xml->spip_auteurs as $auteur ) {
        $id = ( int ) $auteur->id_auteur;
        if ( $login = $auteurs[$id] = (string) $auteur->login ) {
          $authors[$login] = array(
            'author_id' => $id,
            'author_login' => $login,
            'author_email' => ( string ) $auteur->email,
            'author_display_name' => ( string ) $auteur->nom
          );
        }
      }
    }

    // CATEGORIES
    $rubriques = array(); // record for parents, and for relation to posts
    if ( property_exists( $xml, 'spip_rubriques' ) ) {
      foreach ( $xml->spip_rubriques as $rubrique ) {
        $id             = $term_ids[] = ( int ) $rubrique->id_rubrique;
        $name           = self::format_title( ( string ) $rubrique->titre );
        $descriptif     = ( string ) $rubrique->descriptif;
        $texte          = ( string ) $rubrique->texte;
        $rubriques[$id] = array(
          'term_id' => $id,
          'category_nicename' => sanitize_title( $name['title'] ),
          'category_parent' => ( int ) $rubrique->id_parent,
          'cat_name' => $name['title'],
          'category_description' => self::format_post_content( $descriptif . "\n\n" . $texte )
        );
      }
      // Parents are recorded by id, let's use "nicename"
      foreach ( $rubriques as $id => $rubrique ) {
        $id_parent    = $rubrique['category_parent'];
        $categories[] = array_merge( $rubrique, array(
           'category_parent' => $id_parent > 0 ? $rubriques[$id_parent]['category_nicename'] : '0'
        ) );
      }
    }

    // TAGS
    $mots = array(); // record for relation to posts
    if ( property_exists( $xml, 'spip_mots' ) ) {
      foreach ( $xml->spip_mots as $mot ) {
        $id = $id_mot = ( int ) $mot->id_mot;
        // Let's try not to override any term's id, as WP consider both "rubriques" and "mots" as taxonomies
        if ( in_array( $id, $term_ids ) ) {
          $id = max( $term_ids ) + 1;
        }
        $term_ids[] = $id;
        $name       = self::format_title( ( string ) $mot->titre );
        $descriptif = ( string ) $mot->descriptif;
        $texte      = ( string ) $mot->texte;
        $tags[]     = $mots[$id_mot] = array(
          'term_id' => $id,
          'tag_slug' => sanitize_title( $name['title'] ),
          'tag_name' => $name['title'],
          'tag_description' => self::format_post_content( $descriptif . "\n\n" . $texte )
        );
      }
      $mots_articles = array(); // Relations Post/Tag
      foreach ( $xml->spip_mots_articles as $mot ) {
        $id_article = ( int ) $mot->id_article;
        $id_mot     = ( int ) $mot->id_mot;
        if ( empty( $mots_articles[$id_article] ) ) {
          $mots_articles[$id_article] = array();
        }
        $mots_articles[$id_article][] = array(
          'name' => $mots[$id_mot]['tag_name'],
          'slug' => $mots[$id_mot]['tag_slug'],
          'domain' => 'post_tag'
        );
      }
    }

    // COMMENTS
    $comments = array();
    if ( property_exists( $xml, 'spip_forum' ) ) {
      foreach ( $xml->spip_forum as $comment ) {
        if ( $id_article = ( int ) $comment->id_article && 'publie' === ( string ) $article->statut ) {
          if ( empty( $comments[$id_article] ) ) {
            $comments[$id_article] = array();
          }
          $titre                   = ( string ) $comment->titre;
          $texte                   = ( string ) $comment->texte;
          $comments[$id_article][] = array(
            'comment_id' => ( int ) $comment->id_forum,
            'comment_author' => ( string ) $comment->auteur,
            'comment_author_email' => ( string ) $comment->email_auteur,
            'comment_author_IP' => ( string ) $comment->ip,
            'comment_author_url' => ( string ) $comment->url_site,
            'comment_date' => ( string ) $comment->date,
            'comment_date_gmt' => ( string ) $comment->date,
            'comment_content' => self::format_post_content( $titre . "\n\n" . $texte ),
            'comment_parent' => ( int ) $comment->id_parent
          );
        }
      }
    }

    // POSTS
    if ( property_exists( $xml, 'spip_articles' ) ) {
      foreach ( $xml->spip_articles as $article ) {
        $id             = $post_ids[] = ( int ) $article->id_article;
        $id_auteur      = ( int ) $article->id_auteur;
        $id_rubrique    = ( int ) $article->id_rubrique;
        $chapo          = ( string ) $article->chapo;
        $texte          = ( string ) $article->texte;
        $ps             = ( string ) $article->ps;
        $content        = self::format_post_content( $chapo . "\n\n" . $texte . "\n\n" . $ps );
        $excerpt        = ( string ) $article->descriptif;
        $excerpt        = self::format_post_content( $excerpt );
        $comment_status = ( string ) $article->accepter_forum;
        switch ( ( string ) $article->statut ) {
          case 'prepa':
            $status = 'draft';
            break;
          case 'prop':
            $status = 'pending';
            break;
          case 'publie':
            $status = 'publish';
            break;
          default:
            $status = 'trash';
        }
        $mots = empty( $mots_articles[$id] ) ? array() : $mots_articles[$id];
        if ( $id_rubrique !== -1 ) {
          $rubrique = $rubriques[$id_rubrique];
          $mots[]   = array(
            'name' => $rubrique['cat_name'],
            'slug' => $rubrique['category_nicename'],
            'domain' => 'category'
          );
        }
        $post    = self::get_post( $article );
        $attrs   = array(
          'post_author' => isset( $auteurs[$id_auteur] ) ? $auteurs[$id_auteur] : 0,
          'post_id' => $id,
          // We'll use the cat id for type : unique pages are supposed to be of category "-1".
          'post_type' => $id_rubrique === -1 ? 'page' : 'post',
          'post_content' => $content,
          'post_excerpt' => $excerpt,
          'comment_status' => $comment_status === 'oui' ? 'open' : 'closed',
          'status' => $status,
          'terms' => $mots,
          'comments' => isset( $comments[$id] ) ? $comments[$id] : array()
        );
        $posts[] = array_merge( $post, $attrs );
      }
    }

    // ATTACHMENTS
    $documents = array();
    if ( property_exists( $xml, 'spip_documents' ) && property_exists( $xml, 'spip_documents_liens' ) ) {
      $documents_liens = array(); // Relations Post/Attachment
      foreach ( $xml->spip_documents_liens as $lien ) {
        $id_objet                      = ( int ) $lien->id_objet;
        $id_document                   = ( int ) $lien->id_document;
        $documents_liens[$id_document] = $id_objet;
      }
      foreach ( $xml->spip_documents as $document ) {
        $id                        = ( int ) $document->id_document;
        // Let's try not to override any post's id, as WP consider both "articles" and "documents" as post-types
        $parent                    = isset( $documents_liens[$id] ) ? $documents_liens[$id] : 0;
        $path                      = ( string ) $document->fichier;
        // self::$documents_urls is used to replace image tags
        self::$documents_urls[$id] = $url = $documents_url . $path;
        if ( in_array( $id, $post_ids ) ) {
          $id = max( $post_ids ) + 1;
        }
        $post_ids[]  = $id;
        $content     = ( string ) $document->descriptif;
        $content     = self::format_post_content( $content );
        $post        = self::get_post( $document );
        $attrs       = array(
          'post_id' => $id,
          'post_type' => 'attachment',
          'post_content' => $content,
          'attachment_url' => $url,
          'post_parent' => $parent
        );
        $documents[] = array_merge( $post, $attrs );
      }
    }

    // FEATURED IMAGES
    if ( !empty( $_POST['fetch_attachments'] ) ) {
      echo '<div class="updated" style="margin-top:15px;"><p>' . __( 'Retrieving featured images (<em>logos</em>), <strong>please wait</strong>.', 'spip-importer' ) . '</p></div>';
      flush();
      $id = max( $post_ids );
      foreach ( $posts as $i => $post ) {
        $id_article = $post['post_id'];
        $content    = $post['post_content'];
        if ( $logo_url = self::get_logo_url( $id_article ) ) {
          $id                      = $post_ids[] = $id + 1;
          $documents[]             = array(
            'post_id' => $id,
            'post_type' => 'attachment',
            'attachment_url' => $logo_url,
            'post_parent' => $id_article
          );
          $posts[$i]['postmeta'][] = array(
            'key' => '_thumbnail_id',
            'value' => $id
          );
        }
        // Replace the img shortcode if attachments are fetched
        $posts[$i]['post_content'] = preg_replace_callback( '/\<img([0-9]*)\|?(left|center|right)?\>/is', array( __CLASS__, 'replace_image_tag' ), $content );
      }
    }

    // TERMS : For a next version maybe : consider the "groupes de mots" as taxonomies ? What about tags then ?

    echo '<hr style="clear: both; margin: 15px 0;" />';

    return array(
      'authors' => $authors,
      'posts' => array_merge( $posts, $documents ),
      'categories' => $categories,
      'tags' => $tags,
      'terms' => $terms,
      'base_url' => $base_url
    );
  }
}
