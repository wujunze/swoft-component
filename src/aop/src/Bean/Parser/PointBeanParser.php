<?php
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://doc.swoft.org
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */
namespace Swoft\Aop\Bean\Parser;

use Swoft\Aop\Bean\Annotation\PointBean;
use Swoft\Aop\Bean\Collector\AspectCollector;

/**
 * the point annotation of parser
 *
 * @uses      PointBeanParser
 * @version   2017年12月24日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2016 swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class PointBeanParser extends AbstractParser
{
    /**
     * pingBean parsing
     *
     * @param string    $className
     * @param PointBean $objectAnnotation
     * @param string    $propertyName
     * @param string    $methodName
     * @param null      $propertyValue
     *
     * @return null
     */
    public function parser(string $className, $objectAnnotation = null, string $propertyName = '', string $methodName = '', $propertyValue = null)
    {
        AspectCollector::collect($className, $objectAnnotation, $propertyName, $methodName, $propertyValue);

        return null;
    }
}
