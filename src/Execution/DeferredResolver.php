<?php
/**
 * Date: 11.05.17
 *
 * @author Campbell Vertesi <campbell@vertesi.com>
 */

namespace Youshido\GraphQL\Execution;


/**
 * Class DeferredResolver
 *
 * A field resolver may return an instance of this class instead of a value.
 * When there are no more fields to resolve, DeferredResolver instances resolve
 * into real objects in a single operation. The field resolver then resumes
 * looping over the new data.
 *
 * @package Youshido\GraphQL\Execution
 */
class DeferredResolver
{

  /**
   * Id.
   *
   * @var string|array
   *   The information needed to load the target.
   */
    protected $id;

  /**
   * @return array|string
   */
    public function getId()
    {
        return $this->id;
    }

  /**
   * @param array|string $id
   */
    public function setId($id)
    {
        $this->id = $id;
    }

  /**
   * Resolve callback.
   *
   * @var string|array
   *   The callback used for resolution. It will receive the entire list of ids
   *   at once.
   */
    public static $resolveCallback;

    /**
     * @param array|string $resolveCallback
     */
    public static function setResolveCallback($resolveCallback)
    {
        self::$resolveCallback = $resolveCallback;
    }

  /**
   * Get the placeholder string for the resolver target.
   *
   * @return string
   */
    public function getPlaceholder()
    {
        return base64_encode(serialize(self::$resolveCallback) . '-' . $this->getId());
    }

}
