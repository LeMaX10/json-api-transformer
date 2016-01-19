<?php
namespace lemax10\JsonApiTransformer\Response;

use Illuminate\Support\Collection;
use lemax10\JsonApiTransformer\Mapper;

class ObjectResponse
{
	protected $includes = [];
	protected $responseIncludes = [];
	protected $relationships = [];
	protected $responseBody;
	protected $transformer;
	protected $model;

	public function __construct($transformer, $model)
	{
		$this->responseBody = new Collection();
		$this->transformer = $transformer;
		$this->model = $model;
		$this->initIncludes();
	}
	protected function initIncludes()
	{
		$relation = $this->transformer->getRelationships();
		if(\Request::has('includes')) {
			$include = explode(',', \Request::input('includes'));
			foreach ($include as $rel)
			{
				if(strpos($rel, ".") !== false)
				{
					list($relinj, $reltype) = explode('.', $rel);
					if(!isset($relation[$relinj]))
						continue;

					$reltypeInj = (new $relation[$relinj]['transformer'])->getRelationships()[$reltype];
					$this->includes[$rel] = $reltypeInj['transformer'];
					continue;
				}

				if(!isset($relation[$rel]))
					continue;

				$this->includes[$rel] = $relation[$rel]['transformer'];
			}
		}

		if(count($relation)) foreach($relation as $type => $typeOpt)
		{
			if(!isset($this->includes[$type]) && isset($typeOpt['autowired']) && $typeOpt['autowired'] === true)
				$this->includes[$type] = $typeOpt['tranformer'];
		}

		$this->model->load(array_keys($this->includes));
		unset($relation, $include);
	}

	public function response()
	{
		return response()->json($this->getResponse());
	}

	public function getResponse()
	{
		$includes = $this->getIncludes($this->model);
		$data = $this->getModel($this->model);
		if(method_exists($this->model, 'getMeta') && count($this->model->getMeta()))
			$data = array_merge($data, [Mapper::ATTR_META => $this->model->getMeta()]);

		$this->responseBody->put(Mapper::ATTR_DATA, [$data]);
		if(count($includes))
			$this->responseBody->put(Mapper::ATTR_INCLUDES, $includes);
		return $this->responseBody;
	}

	protected function getModel($model = false)
	{
		if(!$model)
			$model = $this->model;

		return array_merge(
			$this->getOptions($model),
			$this->getAttributes($model),
			$this->getRelationships()
		);
	}
	protected function getAttributes($model)
	{
		$model = collect($model)->except(array_keys($this->includes));

		if(\Request::has('filter.'. $this->transformer->getAlias()))
			$model = $model->only(explode(',', \Request::input('filter.' . $this->transformer->getAlias())));

		return [
			Mapper::ATTR_ATTRIBUTES => $model
		];
	}

	protected function getOptions($model)
	{
		$return = [
			Mapper::ATTR_TYPE => $this->transformer->getAlias(),
			'id' => $model->id
		];

		return $return;
	}

	protected function getIncludes($model)
	{
		$includes = [];
		foreach($this->includes as $type => $transformer) {
			if(strpos($type, ".") !== false) {
				list($relation, $method) = explode('.', $type);
				$this->relationships[$method]['data']['id'] = $model->{$relation}->{$method}->id;
				$this->relationships[$method]['data']['type'] = $method;
				$includes[] = (new self(new $transformer, $model->{$relation}->{$method}))->getModel();
				unset($model->{$relation}->{$method});
				continue;

			}

			$this->relationships[$type]['data']['id'] = $model->{$type}->id;
			$this->relationships[$type]['data']['type'] = (new $transformer)->getAlias();
			$includes[] = (new self(new $transformer, $model->{$type}))->getModel();
		}

		return $includes;
	}

	protected function getRelationships()
	{
		if(!count($this->relationships))
			return [];

		return [
			Mapper::ATTR_RELATIONSHIP => $this->relationships
		];
	}
}