<?php

/**
 * Interface to describe how RenderCache controller plugin objects are implemented.
 */
interface RenderCacheControllerInterface {
  public function getContext();
  public function setContext(array $context);

  public function view(array $objects);

  public function isRecursive();
  public function getRecursionLevel();
}

/**
 * RenderCacheController abstract base class.
 */
abstract class RenderCacheControllerAbstractBase extends RenderCachePluginBase implements RenderCacheControllerInterface {
  // -----------------------------------------------------------------------
  // Suggested implementation functions.

  abstract protected function isCacheable(array $default_cache_info, array $context);

  abstract protected function getCacheContext($object, array $context);
  abstract protected function getCacheKeys($object, array $context);
  abstract protected function getCacheHash($object, array $context);
  abstract protected function getCacheTags($object, array $context);
  abstract protected function getCacheValidate($object, array $context);

   /**
   * Render uncached objects.
   *
   * This function needs to be implemented by every child class.
   *
   * @param $objects
   *   Array of $objects to be rendered keyed by id.
   *
   * @return array
   *   Render array keyed by id.
   */
  abstract protected function render(array $objects);

  // -----------------------------------------------------------------------
  // Helper functions.

  abstract protected function getDefaultCacheInfo($context);
  abstract protected function getCacheInfo($object, array $cache_info = array(), array $context = array());

  abstract protected function increaseRecursion();
  abstract protected function decreaseRecursion();

  abstract protected function alter($type, &$data, &$context1 = NULL, &$context2 = NULL, &$context3 = NULL);
}

/**
 * Base class for RenderCacheController plugin objects.
 */
abstract class RenderCacheControllerBase extends RenderCacheControllerAbstractBase {
  /**
   * An optional context provided by this controller.
   */
  protected $context = array();

  /**
   * Recursion level of current call stack.
   */
  protected static $recursionLevel = 0;

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * {@inheritdoc}
   */
  public function setContext(array $context) {
    $this->context = $context;
  }

  /**
   * {@inheritdoc}
   */
  public function view(array $objects) {
    // Retrieve controller context.
    $context = $this->getContext();

    // Get default cache info and allow modules to alter it.
    $default_cache_info = $this->getDefaultCacheInfo($context);
    $this->alter('default_cache_info', $default_cache_info, $context);

    // Bail out early, when this is not cacheable.
    if (!$this->isCacheable($default_cache_info, $context)) {
      return $this->render($objects);
    }

    // Retrieve a list of cache_ids
    $cid_map = array();
    $cache_info_map = array();
    foreach ($objects as $id => $object) {
      $context['id'] = $id;
      $cache_info_map[$id] = $this->getCacheInfo($object, $default_cache_info, $context);
      $cid_map[$id] = $cache_info_map[$id]['cid'];
    }

    $object_order = array_keys($objects);

    $cids = array_filter(array_values($cid_map));

    if (!empty($cids)) {
      $cached_objects = cache_get_multiple($cids, $default_cache_info['bin']);

       // Calculate remaining entities
      $ids_remaining = array_intersect($cid_map, $cids);
      $objects = array_intersect_key($objects, $ids_remaining);
   }

    // Render non-cached entities.
    if (!empty($objects)) {
      $object_build = $this->render($objects);
    }

    $build = array();
    foreach ($object_order as $id) {
      $cid = $cid_map[$id];
      $cache_info = $cache_info_map[$id];

      if (isset($cached_objects[$cid])) {
        $render = $cached_objects[$cid]->data;

        // Potentially merge back previously saved properties.
        // @todo Helper
        if (!empty($render['#attached']['render_cache'])) {
          $render += $render['#attached']['render_cache'];
          unset($render['#attached']['render_cache']);
        }
      } else {
        $render = $object_build[$id];
        if ($cid) {
          $render = $this->cacheRenderArray($render, $cache_info);
        }
      }

      // Unset any weight properties.
      unset($render['#weight']);

      // Run any post-render callbacks.
      render_cache_process_attached_callbacks($render, $id);

      $build[$id] = $render;
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function isRecursive() {
    return static::$recursionLevel > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecursionLevel() {
    return static::$recursionLevel;
  }

  /**
   * {@inheritdoc}
   */
  protected function isCacheable(array $default_cache_info, array $context) {
    $ignore_request_method_check = $default_cache_info['render_cache_ignore_request_method_check'];
    return isset($default_cache_info['granularity'])
        && variable_get('render_cache_enabled', TRUE)
        && variable_get('render_cache_' . $this->getType() . '_enabled', TRUE)
        && render_cache_call_is_cacheable(NULL, $ignore_request_method_check);
  }

   /**
   * {@inheritdoc}
   */
  protected function getCacheContext($object, array $context) {
    return $context;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheKeys($object, array $context) {
    return array(
      'render_cache',
      $this->getType(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheHash($object, array $context) {
    return array(
      'id' => $context['id'],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheTags($object, array $context) {
    return array(
      'content' => TRUE,
      $this->getType() => TRUE,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheValidate($object, array $context) {
    return array();
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultCacheInfo($context) {
    return array(
       // Drupal 7 properties.
       'bin' => 'cache_render',
       'expire' => CACHE_PERMANENT,
       // Use per role to support contextual and its safer anyway.
       'granularity' => DRUPAL_CACHE_PER_ROLE,
       'keys' => array(),

       // Drupal 8 properties.
       'tags' => array(),

       // Render Cache specific properties.
       // @todo Port to Drupal 8.
       'hash' => array(),
       'validate' => array(),

       // Special keys that are only related to our implementation.
       // @todo Remove and replace with something else.
       'render_cache_render_to_markup' => FALSE,
       'render_cache_ignore_request_method_check' => FALSE,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheInfo($object, array $cache_info = array(), array $context = array()) {
    $context = $this->getCacheContext($object, $context);

    $cache_info += array(
      'keys' => array(),
      'hash' => array(),
      'tags' => array(),
      'validate' => array(),
    );

    // Set cache information properties.
    $cache_info['keys'] = array_merge(
      $cache_info['keys'],
      $this->getCacheKeys($object, $context)
    );
    $cache_info['hash'] = array_merge(
      $cache_info['hash'],
      $this->getCacheHash($object, $context)
    );
    $cache_info['tags'] = drupal_array_merge_deep(
      $cache_info['tags'],
      $this->getCacheTags($object, $context)
    );
    $cache_info['validate'] = drupal_array_merge_deep(
      $cache_info['validate'],
      $this->getCacheValidate($object, $context)
    );
 
    // @todo Remove this later.
    $cache_info['hash']['render_method'] = !empty($cache_info['render_cache_render_to_markup']);
    if ($cache_info['hash']['render_method']) {
      $cache_info['hash']['render_options'] = serialize($cache_info['render_cache_render_to_markup']);
    }
   
    $this->alter('cache_info', $cache_info, $object, $context);
  
    // If we can't cache this, return with cid set to NULL.
    if ($cache_info['granularity'] == DRUPAL_NO_CACHE) {
      $cache_info['cid'] = NULL;
      return $cache_info;
    }

    // If a Cache ID isset, we need to skip the rest.
    if (isset($cache_info['cid'])) {
      return $cache_info;
    }
  
    $keys = &$cache_info['keys'];
    $hash = &$cache_info['hash'];

    $tags = &$cache_info['tags'];
    $validate = &$cache_info['validate'];

    // Allow modules to alter the keys, hash, tags and validate.
    $this->alter('keys', $keys, $object, $cache_info, $context);
    $this->alter('hash', $hash, $object, $cache_info, $context);

    $this->alter('tags', $tags, $object, $cache_info, $context);
    $this->alter('validate', $validate, $object, $cache_info, $context);
    
    // Add drupal_render cid_parts based on granularity.
    $granularity = isset($cache_info['granularity']) ? $cache_info['granularity'] : NULL;
    $cid_parts = array_merge(
      $cache_info['keys'],
      drupal_render_cid_parts($granularity)
    );

    // Calculate the hash.
    $algorithm = variable_get('render_cache_hash_algorithm', 'md5');
    $cid_parts[] = hash($algorithm, implode('-', $cache_info['hash']));

    // Allow modules to alter the final cid_parts array.
    $this->alter('cid', $cid_parts, $cache_info, $object, $context);

    $cache_info['cid'] = implode(':', $cid_parts);

    return $cache_info;
  }

  /**
   * {@inheritdoc}
   */
  protected function cacheRenderArray($render, $cache_info) {
    if (empty($cache_info['render_cache_render_to_markup'])) {
      cache_set($cache_info['cid'], $render, $cache_info['bin']);
    }
    else {
      // Process markup with drupal_render() caching.
      $render['#cache'] = $cache_info;

      $render_cache_attached = array();
      // Preserve some properties in #attached?
      if (!empty($cache_info['render_cache_render_to_markup']['preserve properties']) &&
          is_array($cache_info['render_cache_render_to_markup']['preserve properties'])) {
        foreach ($cache_info['render_cache_render_to_markup']['preserve properties'] as $key) {
          if (isset($render[$key])) {
            $render_cache_attached[$key] = $render[$key];
          }
        }
      }
      if (!empty($render_cache_attached)) {
        $render['#attached']['render_cache'] = $render_cache_attached;
      }

      // Do we want to render now?
      if (empty($cache_info['render_cache_render_to_markup']['cache late'])) {
        // And save things. Also add our preserved properties back.
        $render = array(
          '#markup' => drupal_render($render),
        ) + $render_cache_attached;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function increaseRecursion() {
    static::$recursionLevel += 1;
  }

  /**
   * {@inheritdoc}
   */
  protected function decreaseRecursion() {
    static::$recursionLevel -= 1;
  }

  /**
   * {@inheritdoc}
   */
  protected function alter($type, &$data, &$context1 = NULL, &$context2 = NULL, &$context3 = NULL) {
    drupal_alter('render_cache_' . $this->getType() . '_' . $type, $data, $context1, $context2, $context3);
  }
}
