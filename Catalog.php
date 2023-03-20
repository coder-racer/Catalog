<?php

class Catalog
{
    /**
     * @var bool
     */
    public $pagination = false;
    /**
     * @var int
     */
    public $activePage = 0;
    /**
     * @var int
     */
    public $limitPages = 1;
    /**
     * @var int
     */
    public $countProducts = 0;
    /**
     * @var int
     */
    public $totalPages = 0;
    /**
     * @var array
     */
    public $totalFilter = [];
    /**
     * @var int
     */
    public $priceMin = 0;
    /**
     * @var int
     */
    public $priceMax = 0;
    /**
     * @var null
     */
    private static $cats = null;
    /**
     * @var null
     */
    private static $catalog_relations_2 = null;
    /**
     * @var null
     */
    private static $catalog_cats_render = null;

    /**
     *
     */
    public function __construct()
    {
        require_once dirname(__DIR__) . '/classes/CatalogUtils.php';
    }

    /**
     * @return array|false|mixed
     */
    public function getStockOn()
    {
        $time = \g::settings('stock_on');
        if (is_null($time)) {
            \g::db()->query("INSERT INTO `settings` ( `name`, `val`, `placeholder`, `description`, `label`, `type`, `modules`) VALUES
                    ('stock_on', ?s, '', '', '', 'text', 'settings');", '0');
        }
        return is_null($time) ? false : $time;
    }

    // отсортирует массив так , что цены с 0 и меньше будут в конце. $key - ключь по которому сортировать

    /**
     * @param $products
     * @param $key
     * @return array
     */
    private function sortArrNullEnd($products, $key)
    {
        $specSortForDmitry = [];
        $specSortForDmitryNotNull = [];

        foreach ($products as $key_p => $product) {
            (floatval($product[$key]) <= 0) ? $specSortForDmitryNotNull[$key_p] = $product : $specSortForDmitry[] = $product;
        }

        if ($specSortForDmitryNotNull) {
            foreach ($specSortForDmitryNotNull as $key_p => $product) {
                $specSortForDmitry[] = $product;
            }
        }
        return $specSortForDmitry;
    }

    /**
     * @return array|null
     */
    private function getAllCat()
    {
        if (is_null(self::$cats)) {
            self::$cats = $this->getProductIds($this->getIdCategoriesForParent(\g::db()->getOne("SELECT `id` FROM `catalog_categories` WHERE  `url` = ?s AND `active` = ?i", 'products', 1)));
        }
        return self::$cats;
    }

    /**
     * @param $size
     * @return array
     */
    public function randomCategory($size)
    {
        return [];
        $data = \g::db()->getAll("SELECT * FROM `catalog_categories` WHERE `active` = ?s AND `id` <> 1 ORDER BY RAND() LIMIT ?i", 1, $size);


        foreach ($data as &$item) {
            $item = \g::CatalogUtils()->dataRender($item, 'category', false);
        }
        return $data;
    }

    /**
     * @param $size
     * @return array
     */
    public function randomProducts($size)
    {

        $whereStock = "";
        if ($this->getStockOn())
            $whereStock = " AND stock > 0";
        $products = \g::db()->getAll("SELECT * FROM `catalog_products` WHERE `active` = ?s AND `price` > 0 AND `id` IN(?a) " . $whereStock . " ORDER BY RAND() LIMIT ?i", 1, $this->getAllCat(), $size);

        \g::CatalogUtils()->RenderLangProducts($products);
        foreach ($products as &$product) {
            if (empty($product))
                unset($product);
            else {
                $product = \g::CatalogUtils()->dataRender($product, 'product', false);
            }
        }
        shuffle($products);
        $products = $this->sortArrNullEnd($products, 'price'); // магия по прозьбе Главного начальника
        return $products;
    }

    /*
     *  Получаем по id категории, id всех детей
     */

    /**
     * @param $array
     * @param $cid
     * @return array
     */
    private function getParentId($array, $cid)
    {
        $result = [];
        foreach ($array as $item) {
            if ($item['pid'] == $cid) {
                $result = array_merge($result, $this->getParentId($array, $item['cid']));
                $result[] = $item['cid'];

            }
        }
        return $result;
    }

    /**
     * @param $id
     * @return mixed
     */
    public function getProductId($id)
    {
        $product = \g::getRow("SELECT * FROM `catalog_products`
                WHERE `active` = ?s AND
                `id` = ?i", 1, $id);
        return $product;
    }

    /**
     * @return array|null
     */
    public function getAllCategories()
    {
        if (is_null(self::$catalog_relations_2))
            self::$catalog_relations_2 = \g::db()->getAll("SELECT `cid`, `pid` FROM catalog_relations WHERE type = 2");

        return self::$catalog_relations_2;
    }

    /**
     * @return array|null
     */
    public function getAllFormatCategories()
    {
        if (!is_null(self::$catalog_cats_render))
            return self::$catalog_cats_render;

        self::$catalog_cats_render = [];
        $ids = [];
        foreach ($this->getAllCategories() as $item) {
            $ids[] = $item['cid'];
        }

        self::$catalog_cats_render = \g::db()->getAll("SELECT * FROM catalog_categories WHERE active = 1 AND id IN(?a)", $ids);

        foreach (self::$catalog_cats_render as &$item) {
            foreach ($this->getAllCategories() as $info) {
                if ($info['cid'] == $item['id'])
                    $item['parent_id'] = $info['pid'];
            }
        }

        return self::$catalog_cats_render;
    }

    /**
     * @param $categories_id
     * @return array
     */
    public function getIdCategoriesForParent($categories_id = 0)
    {
        if (is_null(self::$catalog_relations_2))
            self::$catalog_relations_2 = \g::db()->getAll("SELECT `cid`, `pid` FROM catalog_relations WHERE type = 2");
        $ids = array_unique($this->getParentId(self::$catalog_relations_2, $categories_id));
        $ids[] = $categories_id;
        sort($ids);
        return $ids;
    }

    /**
     * @param $ids
     * @return array
     */
    public function getProductIds($ids)
    {
        $result = [];
        $id_list = \g::db()->getAll("SELECT `cid` FROM catalog_relations WHERE type = 1 AND `pid` IN(?a)", $ids);
        foreach ($id_list as $id) {
            $result[] = $id['cid'];
        }
        sort($result);
        return $result;
    }

    /**
     * @param $array1
     * @param $array2
     * @return bool
     */
    private function CheckArrayInArray($array1, $array2)
    {
        if (count(array_intersect($array1, $array2)) > 0)
            return true;
        return false;
    }

    /**
     * @param $url
     * @return array
     */
    public function getCategoriesForUrlArray($url = [])
    {
        $list = \g::db()->getAll("SELECT * FROM `catalog_categories` WHERE  `url` IN (?a) AND `active` = ?i ORDER BY `sort`", $url, 1);
        foreach ($list as &$item) {
            $item['url'] = '/catalog/category/' . $item['url'] . '/';
        }

        return array_reverse($list);
    }

    /**
     * @param $url
     * @param $sort
     * @param $orderBy
     * @param $active
     * @return array|false
     */
    public function getCategoriesForParentIdAction($url = 'products', $sort = 'sort', $orderBy = 'asc', $active = false)
    {
        $active_SQL = '';
        if ($active) {
            $active_SQL = ' AND c.active = 1';
        }
        $result = \g::db()->getAll("
            SELECT * FROM `catalog_categories` c 
                LEFT JOIN `catalog_relations` r ON c.id = r.cid 
            WHERE r.`type` = 2" . $active_SQL . " AND
                  r.`pid` = (SELECT `id` FROM `catalog_categories` WHERE  `url` = ?s AND `active` = ?i)
            ORDER BY c." . $sort . " " . $orderBy, $url, 1) ?? false;

        foreach ($result as &$item) {
            \g::CatalogUtils()->RenderLangCategory($item);
            $item = \g::CatalogUtils()->dataRender($item);
        }

        return $result;
    }

    /**
     * @param $limit
     * @param $url
     * @return array
     */
    public function lastProducts($limit = 5, $url = 'products')
    {

        $whereStock = "";
        if ($this->getStockOn())
            $whereStock = " AND stock > 0";
        $products = \g::db()->getAll(
            "SELECT * FROM `catalog_products`
                WHERE `active` = ?s AND `price` > 0  AND
                `id` IN ( ?a ) " . $whereStock . " ORDER BY `time_creation` DESC LIMIT ?i",
            1,
            $this->getAllCat(),
            $limit);


        if (count($products) < 1)
            return [];
        foreach ($products as &$product) {
            $product = \g::CatalogUtils()->dataRender($product, 'product');
        }

        $products = $this->sortArrNullEnd($products, 'price');
        return $products;

    }

    /**
     * @param $url
     * @param $sort
     * @param $orderBy
     * @return array|false
     */
    public function categories($url = 'products', $sort = 'sort', $orderBy = 'asc')
    {
        return $this->getCategoriesForParentIdAction($url, $sort, $orderBy, true);
    }

    /**
     * @param $ids
     * @return array
     */
    public function productsListByIdList($ids = [])
    {

        $whereStock = "";
        if ($this->getStockOn())
            $whereStock = " AND stock > 0";
        $products = \g::db()->getAll(
            "SELECT p.*, cat.name as `category_name`, cat.url as `category_url` FROM `catalog_products` as p LEFT JOIN `catalog_relations` as r ON p.id = r.cid LEFT JOIN `catalog_categories` as cat 
                ON r.pid = cat.id
                WHERE p.active = 1 AND cat.active = 1 AND r.type = 1 AND
                p.id IN ( ?a ) " . $whereStock,
            $ids);


        if (count($products) < 1)
            return [];
        foreach ($products as &$product) {
            $product = \g::CatalogUtils()->dataRender($product, 'product');
            $product['category_url'] = '/catalog/category/' . $product['category_url'];
        }
        return $products;
    }

    /**
     * @return false|string
     */
    private function generateMinMaxPrice()
    {
        $min = is_numeric(\g::request()->get()->get('min')) ? \g::request()->get()->get('min') : false;
        $max = is_numeric(\g::request()->get()->get('max')) ? \g::request()->get()->get('max') : false;

        $SQL_MIN_MAX = false;

        if ($min != false) {
            $SQL_MIN_MAX = 'AND price >= ' . $min;
        }

        if ($max != false) {
            if ($SQL_MIN_MAX != false) {
                $SQL_MIN_MAX .= ' AND price <= ' . $max;
            } else {
                $SQL_MIN_MAX = 'AND price <= ' . $max;
            }
        }

        if ($SQL_MIN_MAX == false) {
            $SQL_MIN_MAX = '';
        }

        return $SQL_MIN_MAX;
    }

    /**
     * @param $arr
     * @return array
     */
    public function textArrToNumArr($arr)
    {
        $num = [];
        foreach ($arr as $item) {
            if (is_numeric($item)) {
                $num[] = (float)$num;
            }
        }
        return $num;
    }

    /**
     * @param $filters
     * @return array
     */
    public function renderFilters($filters)
    {
        $ids_filters = [];
        foreach ($filters as $item) {
            $ids_filters[$item['parent_id']][$item['name']][] = $item['value'];
        }
        foreach (\g::request()->get()->get('filters') as $filterName => $filterValue) {
            foreach ($filterValue as $value => $minMax) {
                $pick = false;
                foreach ($ids_filters as $key => $item) {
                    if (!array_key_exists($filterName, $ids_filters[$key])) {
                        unset($ids_filters[$key]);
                        continue;
                    }
                    if ($value == 'min_filter') {
                        $min = min($this->textArrToNumArr($ids_filters[$key][$filterName]));
                        if ($min < $minMax) {
                            unset($ids_filters[$key]);
                            continue;
                        }
                    }

                    if ($value == 'max_filter') {
                        $max = max($this->textArrToNumArr($ids_filters[$key][$filterName]));
                        if ($max > $minMax) {
                            unset($ids_filters[$key]);
                            continue;
                        }
                    }
                    if (!in_array($value, $ids_filters[$key][$filterName])) {
                        unset($ids_filters[$key]);
                        continue;
                    }
                    continue;
                }
            }
        }
        $ids = [];
        foreach ($ids_filters as $key => $item) {
            $ids[] = $key;
        }
        return $ids;
    }

    /*
     *   Все данные вертятся на криенте из-за beget
     */

    /**
     * @param $ids
     * @param $url
     * @return array
     */
    public function getAllFilters($ids, $url)
    {
        $lim = " ";
        if ($url == "products")
            $lim = "LIMIT 10000";
        $totalFilter = \g::db()->getAll("SELECT `value`, `name`, `type`, `parent_id`  FROM catalog_filters WHERE `parent_id` IN ( ?a ) AND filter = 1 $lim", $ids);
        foreach ($totalFilter as $item) {
            if (!isset($this->totalFilter[$item['name']]) || (is_array($this->totalFilter[$item['name']]['list']) && !in_array($item['value'], $this->totalFilter[$item['name']]['list']))) {
                $this->totalFilter[$item['name']]['list'][] = $item['value'];
                $this->totalFilter[$item['name']]['type'] = $item['type'];

            }
        }
        return $totalFilter;
    }

    /**
     * @param $SQL
     * @param $parse
     * @return mixed|string
     */
    private function parseAdd($SQL, $parse)
    {
        if ($SQL == NULL) {
            return $parse;
        } else {
            return \g::db()->parse(" ?p ?p", $SQL, $parse);
        }
    }

    /**
     * @param $ids
     * @return array[]
     */
    public function checkFilter($ids)
    {
        $SQL = [];
        $allFilters = [];
        $min_list = [];
        $max_list = [];
        foreach (\g::request()->get()->get('filters') as $filterName => $filterValue) {
            if (!in_array($filterName, $allFilters))
                $allFilters[] = $filterName;
            $SQL_filter_one = NULL;
            foreach ($filterValue as $value => $minMax) {
                if ($value == 'min_filter') {
                    if (!in_array($filterName, $min_list)) {
                        $min_list[] = $filterName;
                        if (is_numeric($minMax))
                            $SQL[] = \g::db()->parse("DELETE FROM `tmp_table` WHERE (`filter_name` = ?s AND `filter_value` < ?i);", $filterName, $minMax);
                        continue;
                    }
                }

                if ($value == 'max_filter') {
                    if (!in_array($filterName, $max_list)) {
                        $max_list[] = $filterName;
                        if (is_numeric($minMax))
                            $SQL[] = \g::db()->parse("DELETE FROM `tmp_table` WHERE (`filter_name` = ?s AND `filter_value` > ?i);", $filterName, $minMax);
                        continue;
                    }
                }
                if (!in_array($filterName, $max_list) && !in_array($filterName, $min_list))
                    $SQL_filter_one = $this->parseAdd($SQL_filter_one, \g::db()->parse("AND `filter_value` <> ?s", $value));
            }
            if ($SQL_filter_one != NULL) {
                $SQL[] = \g::db()->parse("DELETE FROM `tmp_table` WHERE (`filter_name` = ?s ?p);", $filterName, $SQL_filter_one);
            }
        }
        return [
            'all' => $allFilters,
            'sql' => $SQL
        ];
    }

    /**
     * @param $id
     * @param $data
     * @return array|mixed
     */
    public function renderParent($id, $data = [])
    {
        $parent = null;
        foreach ($this->getAllFormatCategories() as $cat) {
            if ($cat['id'] == $id) {
                $parent = $cat;
                break;
            }
        }

        if (is_null($parent))
            return $data;

        $data[] = $parent;

        if ($parent['parent_id'] == 0)
            return $data;

        return $this->renderParent($parent['parent_id'], $data);
    }

    /**
     * @param $id
     * @return array|mixed
     */
    public function getProductParents($id)
    {
        if (is_array($id)) {
            $parent_ids = \g::db()->getAll("SELECT `pid`, c.`id` FROM catalog_relations r LEFT JOIN catalog_products c ON c.id = r.cid WHERE r.type = 1 AND c.id IN(?a)", $id);
            $result = [];
            foreach ($parent_ids as $item) {
                $result[$item['id']] = $this->renderParent($item['pid']);
            }

            return $result;
        }
        $parent_id = \g::db()->getOne("SELECT `pid` FROM catalog_relations r LEFT JOIN catalog_products c ON c.id = r.cid WHERE r.type = 1 AND c.id = ?s", $id);
        return $this->renderParent($parent_id);
    }

    /**
     * @param $url
     * @param $limit
     * @param $filters
     * @param $sort
     * @param $orderBy
     * @return array
     */
    public function products($url = 'products', $limit = 12, $filters = false, $sort = 'id', $orderBy = 'desc')
    {
        $whereStock = "";
        if ($this->getStockOn())
            $whereStock = " AND stock > 0";
        //
        $this->limitPages = $limit;
        if (\g::request()->get()->get('psort')) {
            $psort = explode('-', \g::request()->get()->get('psort'));
            if ($psort[0] != 'sort')
                $sort = $psort[0];
            $orderBy = $psort[1];
        }
        $this->activePage = \g::request()->get()->get('page') ?: 1;
        $SQL_MIN_MAX = $this->generateMinMaxPrice();
        // ---- нет проблем
        $category = $this->getIdCategoriesForParent(\g::db()->getOne("SELECT `id` FROM `catalog_categories` WHERE  `url` = ?s AND `active` = ?i", $url, 1));
        $products_ids = $this->getProductIds($category);

        $filters = $this->getAllFilters($products_ids, $url);

        $SQL_FILTER = $this->checkFilter($products_ids);

        $SQLfilersCheck = \g::db()->parse();
        if (count($SQL_FILTER['all'])) {
            $SQLfilersCheck = \g::db()->parse("AND f.name IN (?a)", $SQL_FILTER['all']);
        }
        $count_products_query = '';
        if (count($SQL_FILTER['all'])) {
            \g::db()->query("DROP TEMPORARY TABLE IF EXISTS `tmp_table`;");
            \g::db()->query("DROP TEMPORARY TABLE IF EXISTS `tmp_table_test`;");
            $products = \g::db()->query("
            CREATE TEMPORARY TABLE `tmp_table` 
                    SELECT 
                        p.name as 'name',
                        p.id as 'product_id',
                        f.name as 'filter_name',
                        f.value as 'filter_value'
                    FROM 
                        `catalog_filters` as f
                    LEFT JOIN 
                        `catalog_products` as p
                    ON
                        p.`id` = f.`parent_id`
                    WHERE
                         p.active = 1 ?p AND p.id IN (?a)
                    AND p.active = 1 " . $SQL_MIN_MAX . "
                    AND NOT f.name IS NULL
                    " . $whereStock . "
                    AND NOT f.value IS NULL;
        ", $SQLfilersCheck, $products_ids);

            foreach ($SQL_FILTER['sql'] as $query) {
                $products = \g::db()->query("?p", $query);
            }

            $products = \g::db()->query("
            CREATE TEMPORARY TABLE `tmp_table_test` SELECT `name`, `product_id`, `filter_name`, `filter_value` FROM `tmp_table` GROUP BY `product_id`, `filter_name`
        ");

            // $count_products_query = \g::db()->query("SELECT COUNT(id) as 'count' FROM `catalog_products` WHERE id IN (SELECT `product_id` FROM `tmp_table_test` GROUP BY `product_id`)");
            $count_products_query = \g::db()->query("SELECT COUNT(*) as 'count' FROM `catalog_products` WHERE id in(SELECT `product_id` FROM `tmp_table_test` GROUP BY `product_id` HAVING COUNT(*) = ?i)", count($SQL_FILTER['all']));
        } else {
            $count_products_query = \g::db()->query("SELECT COUNT(*) as 'count' FROM `catalog_products` as p WHERE p.active = 1 " . $SQL_MIN_MAX . " AND p.id IN (?a)" . $whereStock, $products_ids);
        }


        $count_products = 0;
        if ($count_products_query->num_rows) {
            $count_products = $count_products_query->fetch_assoc();
        }

        $count_products = $count_products['count'];
        $this->countProducts = $count_products;
        $this->totalPages = ceil($count_products / $limit);
        if ($this->totalPages > 1) {
            $this->pagination = true;
        }

        if ($this->activePage > $this->totalPages) {
            $this->activePage = $this->totalPages;
        }

        if ($count_products < 1) {
            return [];
        }
        $priceMinMax = [];
        if (count($SQL_FILTER['all'])) {
            $priceMinMax = \g::db()->getRow("SELECT MAX( p.`price`) as 'max', MIN( p.`price`) as 'min'
                FROM `catalog_products` as `p` WHERE id IN (SELECT `product_id` FROM `tmp_table_test` GROUP BY `product_id` HAVING COUNT(*) = ?i)"
                , count($SQL_FILTER['all']));

        } else {
            $priceMinMax = \g::db()->getRow("SELECT MAX( p.`price`) as 'max', MIN( p.`price`) as 'min'
                FROM `catalog_products` as p 
               WHERE p.active = 1 AND p.id IN (?a) " . $whereStock, $products_ids);


        }
        $this->priceMin = round($priceMinMax['min']);

        $this->priceMax = round($priceMinMax['max']);

        $offset = ($this->activePage - 1) * $limit;

        if ($offset <= -1) {
            $offset = 0;
        }

        if (count($SQL_FILTER['all'])) {
            $products = \g::db()->query("       
                SELECT p.`id`, p.`url`, p.`name`, p.`name_abbr`, p.`title`, p.`keywords`, p.`description`, p.`content`, p.`templates`, p.`images`, p.`time_creation`, p.`active`, p.`stock`, p.`price`, p.`price_old`, p.`sku`, p.`last_modified`, p.`views`, p.`is_nav`, p.`nav_parent_url`, p.`sort_nav`, p.`sort` 
                FROM `catalog_products` as `p` WHERE id IN (SELECT `product_id` FROM `tmp_table_test` GROUP BY `product_id` HAVING COUNT(*) = ?i)
                ORDER BY " . $sort . " " . $orderBy . "
                LIMIT ?i, ?i
        ", count($SQL_FILTER['all']), $offset, $limit);
        } else {
            $products = \g::db()->query("       
               SELECT p.`id`, p.`url`, p.`name`, p.`name_abbr`, p.`title`, p.`keywords`, p.`description`, p.`content`, p.`templates`, p.`images`, p.`time_creation`, p.`active`, p.`stock`, p.`price`, p.`price_old`, p.`sku`, p.`last_modified`, p.`views`, p.`is_nav`, p.`nav_parent_url`, p.`sort_nav`, p.`sort` 
                FROM `catalog_products` as p 
               WHERE p.active = 1 " . $SQL_MIN_MAX . " AND p.id IN (?a) " . $whereStock . "
               ORDER BY " . $sort . " " . $orderBy . "  
               LIMIT ?i, ?i
        ", $products_ids, $offset, $limit);
        }
        $result = [];
        if ($products->num_rows) {
            while ($row = $products->fetch_assoc()) {
                $result[] = $row;

            }
        }

        \g::CatalogUtils()->RenderLangProducts($result);
        $result = \g::CatalogUtils()->dataRenderProducts($result);

        //foreach ($result as &$product) {
        //  $product = \g::CatalogUtils()->dataRender($product, 'product', false);
        //}
        return $result;
    }
}
