<?php


namespace TikTokFeeds\Vendor\DI;

use TikTokFeeds\Vendor\Psr\Container\NotFoundExceptionInterface;
/**
 * Exception thrown when a class or a value is not found in the container.
 */
class NotFoundException extends \Exception implements NotFoundExceptionInterface
{
}
