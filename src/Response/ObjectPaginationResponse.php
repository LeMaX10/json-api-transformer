<?php
namespace lemax10\JsonApiTransformer\Response;

use App\Http\Requests\PaginationRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use lemax10\JsonApiTransformer\Mapper;
use Request;
class ObjectPaginationResponse extends ObjectResponse {

	protected $pagination;
	protected $pageName = 'page';
	protected $pageSizeAttr = 'size';
	protected $pageNumberAttr = 'number';
	protected $pageSortAttr = 'sort';

	protected $request;

	public function __construct($transformer, $object, PaginationRequest $request)
	{
		$this->responseBody = new Collection(['jsonapi'   => '1.0']);
		$this->setTransformer($transformer);
		$this->request     = $request;
		if($object) {
			Paginator::currentPageResolver(function (){
				return $this->request->input(join('.', [$this->pageName, $this->pageNumberAttr]), 1);
			});

			$this->model = $this->parseSort($object);
			$this->initIncludes();

			$this->pagination = $this->model->paginate($request->input(join('.', [$this->pageName, $this->pageSizeAttr]), 10));
			$this->model = $this->pagination->getCollection();
		}
	}

	public function initIncludes()
	{
		$this->relation = $this->transformer->getRelationships();
		if(count($this->getLoadingIncluded()))
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

	protected function getLinks() : array
	{
		$this->pagination->setPageName('page[number]')->appends('page[size]', $this->pagination->perPage());
		if($this->request->has(join('.', [$this->pageName, $this->pageSortAttr])))
			$this->pagination->appends('page[sort]', $this->request->input(join('.', [$this->pageName, $this->pageSortAttr])));

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

	protected function getMetaLinks() : array
	{
		return [
			'total-pages' => $this->pagination->lastPage(),
			'page-size'   => $this->pagination->perPage(),
			'currentPage' => $this->pagination->currentPage(),
		];
	}

	private function parseSort($model)
	{
		if(!($sort = $this->request->input(join('.', [$this->pageName, $this->pageSortAttr]), false)))
			return $model;

		$key = '';
		foreach(explode(',', $sort) as $attr) {
			if(empty($key)) {
				$key = static::uncamelcase($attr);
				continue;
			}

			$model->orderBy($key, in_array($attr, ['asc', 'desc']) ? $attr : 'desc');
			$key = '';
		}

		return $model;
	}

	public static function uncamelcase($key, $delimeter="_") : string
	{
		return strtolower(preg_replace('/(?!^)[[:upper:]][[:lower:]]/', '$0', preg_replace('/(?!^)[[:upper:]]+/', $delimeter.'$0', $key)));
	}


}