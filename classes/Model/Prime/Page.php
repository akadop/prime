<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Prime Page Model
 *
 * @author Birkir Gudjonsson (birkir.gudjonsson@gmail.com)
 * @package Prime
 * @category Model
 * @copyright (c) 2013 SOLID Productions
 */
class Model_Prime_Page extends ORM {

	protected $_has_many = [
		'pages' => [
			'model'       => 'Prime_Page',
			'foreign_key' => 'parent_id',
			'far_key'     => 'id'
		],
		'regions' => [
			'model'       => 'Prime_Region',
			'foreign_key' => 'prime_page_id',
			'far_key'     => 'id'
		]
	];

	public function selected($path = NULL)
	{
		// default page
		if (empty($path))
		{
			return $this->where('id', '=', Arr::get(Prime::$config, 'default_page_id', NULL))
			->find();
		}

		// split url path
		$uri = explode('/', $path);

		// initialize last found page as ORM model
		$last = ORM::factory('Prime_Page');

		// loop through uri
		for ($i = 0; $i < count($uri); $i++)
		{
			// get slug
			$slug = $uri[$i];

			// build page orm
			$page = ORM::factory('Prime_Page')
			->base()
			->where('slug', '=', $slug)
			->where('parent_id', ! isset($page) ? 'IS' : '=', ! isset($page) ? NULL : $page->id)
			->find();

			if ( ! $page->loaded())
				break;

			// set last page
			$last = $page;
		}

		// check last page for regions uri
		if ( ! $page->loaded() AND $last->loaded())
		{
			// combine overload parts
			$uri = implode('/', array_slice($uri, $i));

			// loop through regions
			foreach ($last->regions->order_by('position', 'ASC')->find_all() as $region)
			{
				// check if module routes
				if ($region->module()->route($uri))
				{
					// set overload uri
					Prime::$page_overload_uri = $uri;

					// return last loaded page
					return $last;
				}
			}
		}

		return $page;
	}

	/**
	 * Get page absolute uri
	 *
	 * @param ORM Page object
	 * @return string
	 */
	public function uri()
	{
		$page = $this;

		$uri = [$page->slug];

		if ( ! $page->loaded())
			return;

		while ($page->loaded())
		{
			$page = ORM::factory('Prime_Page')
			->where('deleted', '=', 0)
			->where('id', '=', $page->parent_id)
			->find();

			$uri[] = $page->slug;
		}

		$uri = array_reverse($uri);

		return implode('/', $uri);
	}

	public function slug($str = NULL)
	{
		$str = UTF8::strtolower($str);
		$str = str_replace(' ', '-', $str);
		$str = UTF8::transliterate_to_ascii($str);
		$str = str_replace('&', 'and', $str);
		$str = preg_replace('/[^\w-]+/', NULL, $str);
		$str = preg_replace('/\-+/', '-', $str);
		$str = preg_replace('/\-$/', '', $str);

		return $str;
	}

	public function save(Validation $validation = NULL)
	{
		// create position if non existent
		if ( ! $this->loaded() AND $this->position !== NULL)
		{
			$this->position = DB::select([DB::expr('MAX(`position`)'), 'pos'])
			->from('prime_pages')
			->where('parent_id', $this->parent_id === NULL ? 'IS' : '=', $this->parent_id)
			->limit(1)
			->execute()
			->get('pos') + 1;
		}

		if ((bool) $this->slug_auto === TRUE)
		{
			$this->slug = $this->slug($this->name);
			$count = 0;

			$na = (bool) DB::select([DB::expr('COUNT(*)'), 'sum'])->from('prime_pages')
			->where('parent_id', $this->parent_id === NULL ? 'IS' : '=', $this->parent_id)
			->where('id', '!=', $this->id)
			->where('slug', '=', $this->slug)
			->execute()
			->get('sum', 0);

			while ($na)
			{
				$this->slug .= '-'.++$count;

				$na = (bool) DB::select([DB::expr('COUNT(*)'), 'sum'])->from('prime_pages')
				->where('parent_id', $this->parent_id === NULL ? 'IS' : '=', $this->parent_id)
				->where('id', '!=', $this->id)
				->where('slug', '=', $this->slug)
				->execute()
				->get('sum', 0);
			}
		}

		return parent::save($validation);
	}

	public function base()
	{
		// only not deleted pages
		$this->where('deleted', '=', 0);

		// order by item position ascending
		$this->order_by('position', 'ASC');

		// return ORM for further process
		return $this;
	}

	/**
	 * Recursivly find sub pages of loaded record
	 * @return ORM
	 */
	public function recursive()
	{
		return ORM::factory('Prime_Page')
		->base()
		->where('parent_id', $this->loaded() ? '=' : 'IS', $this->loaded() ? $this->id : NULL);
	}

} // End Prime Page