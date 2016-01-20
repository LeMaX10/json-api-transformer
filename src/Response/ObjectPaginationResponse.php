<?php
namespace lemax10\JsonApiTransformer\Response;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use lemax10\JsonApiTransformer\Mapper;
use Request;
class ObjectPaginationResponse extends ObjectResponse {

	protected $pagination;

	public function __construct($transformer, $object)
	{
		$this->responseBody = new Collection(['jsonapi'   => '1.0']);
		$this->transformer = $transformer;
		if($object) {
			$this->model = $object;
			$this->initIncludes();

			$this->pagination = $this->model->paginate(Request::input('page.size', 10));
			$this->model = $this->pagination->getCollection();
		}
	}

	public function initIncludes()
	{
		$this->relation = $this->transformer->getRelationships();
		$this->queryIncludes();
		$this->autowiredIncludes();

		if(count($this->includes))
			$this->model->with($this->getLoadingIncluded());
	}

	public function transformModel($model)
	{
		$this->model = $model;
		return parent::transformModel($model);
	}

	public function response()
	{
		$this->responseBody->put(Mapper::ATTR_LINKS, $this->getLinks());
		$this->responseBody->put(Mapper::ATTR_META, $this->getMetaLinks());
		return parent::response();
	}

	protected function getLinks()
	{
		$this->pagination->setPageName('page[number]')->appends('page[size]', $this->pagination->perPage());
		$links = [
			'self' => $this->pagination->url($this->pagination->currentPage()),
			'first' => $this->pagination->url(1)
		];
		if($this->pagination->currentPage() > 1 && $this->pagination->currentPage() <= $this->pagination->lastPage())
			$links['prev'] = $this->pagination->url($this->pagination->currentPage() - 1);

		if($this->pagination->hasMorePages())
			$links['next'] = $this->pagination->nextPageUrl();

		$links['last'] = $this->pagination->url($this->pagination->lastPage());

		return $links;
	}

	protected function getMetaLinks()
	{
		return [
			'total-pages' => $this->pagination->lastPage(),
			'page-size'   => $this->pagination->perPage(),
			'currentPage' => $this->pagination->currentPage(),
		];
	}

}