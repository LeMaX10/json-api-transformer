<?php
/**
 * Created by PhpStorm.
 * User: lemax
 * Date: 23.12.15
 * Time: 15:47
 */

namespace lemax10\JsonApiTransformer;

interface MapperController {
	/**
     * Метод описывающий маппер для контроллера
     *
     * @return object | array
     */
	public function getMapper();
}