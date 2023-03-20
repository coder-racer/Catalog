<?php

class CatalogUtils
{
    /**
     * @var mixed|string
     */
    public $currency = "";
    /**
     * @var mixed|string
     */
    public $currencyCode = "";
    /**
     * @var mixed|string
     */
    public $declensionProductCount = "";
    /**
     * @var mixed|string
     */
    public $decimals = "";
    /**
     * @var mixed|string
     */
    public $image_views = "";
    /**
     * @var null
     */
    public static $round_count = null;

    /**
     *
     */
    public function __construct()
    {
        if (file_exists(ROOT . '/config/catalog.php')) {
            $catalog = include ROOT . '/config/catalog.php';
            $this->currency = $catalog['currency'];
            $this->currencyCode = $catalog['currency_code'];
            $this->declensionProductCount = $catalog['declension_product'];
            $this->decimals = $catalog['decimals'];
            $this->image_views = isset($catalog['image_views']) ? $catalog['image_views'] : 'mid';
            unset($catalog);
        }
    }

    /**
     * @param $id
     * @return array|false|mixed|null
     */
    public function GetProductForId($id)
    {
        $query_category = \g::db()->getRow("SELECT * FROM `catalog_products` WHERE  `id` = ?s AND `active` = ?i", $id, 1);
        return $this::dataRender($query_category, "product");
    }

    /**
     * @param $digit
     * @param $expr
     * @param $onlyword
     * @return string
     */
    public function declension($digit, $expr, $onlyword = false)
    {
        $digit = preg_replace('/[^0-9]+/s', '', $digit);
        if (!is_numeric($digit)) {
            return '';
        }
        if (!is_array($expr))
            $expr = array_filter(explode(' ', $expr));
        if (empty($expr[2]))
            $expr[2] = $expr[1];
        $i = $digit % 100;
        if ($onlyword)
            $digit = '';

        if ($i >= 5 && $i <= 20) {
            $res = $digit . ' ' . $expr[2];
        } else {
            $i %= 10;
            if ($i == 1)
                $res = $digit . ' ' . $expr[0];
            elseif ($i >= 2 && $i <= 4)
                $res = $digit . ' ' . $expr[1];
            else
                $res = $digit . ' ' . $expr[2];
        }
        return trim($res);
    }

    /**
     * @param $price
     * @param $price_zero
     * @return int|mixed|string
     */
    public function price_format($price, $price_zero = true)
    {
        return is_numeric($price) && $price !== 0 ?
            $this->number_format($price) . ' ' . $this->currency :
            ($price_zero < 0.1 ? $this->price_zero_value() : '0 ' . $this->currency);
    }

    /**
     * @return float|int|string|null
     */
    public function getCountRound()
    {
        if (is_null(self::$round_count)) {
            $time = \g::settings('round_catalog');
            self::$round_count = is_numeric($time) ? $time : 0;
        }
        return self::$round_count;
    }

    /**
     * @param $number
     * @return string
     */
    public function number_format($number)
    {
        return number_format($number, $this->getCountRound(), ',', ' ');
    }

    /**
     * @return int|mixed
     */
    public function price_zero_value()
    {
        if (method_exists(g::cart(), 'settings')) {
            return \g::cart()->config_cart['settings']['shipping_text'];
        }
        return 0;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function dataRenderProducts($data)
    {
        $ids = [];

        foreach ($data as $item) {
            $ids[] = $item['id'];
        }

        if (method_exists(\g::module('DynamicFields'), 'getFields')) {
            $fieldValues = \g::db()->getAll("SELECT name, value, pid FROM `dynamic_fields_list` WHERE pid in( ?a ) AND ptype = ?s",
                $ids,
                'product'
            );

            foreach ($fieldValues as $item) {
                $fields[$item['pid']][$item['name']]['value'] = $item['value'];
            }
            unset($fieldValues);

            foreach ($fields as $key => $item)//$data
            {
                foreach ($data as &$product) {
                    if ($product['id'] == $key)
                        $product['properties'] = $item;
                }
            }

        }

        foreach ($data as &$product) {
            //  \g::CatalogUtils()->RenderLangProduct($product);
            $product = \g::CatalogUtils()->dataRender($product, 'product', false);

        }


        return ($data);
    }

    /**
     * @param $data
     * @param $type
     * @param $propertis
     * @return array|false|mixed|null
     */
    public function dataRender($data, $type = 'category', $propertis = true)
    {
        $field_name = ['name', 'name_abbr', 'title', 'keywords', 'description', 'anons', 'content'];

        foreach ($field_name as $item) {
            $data[$item] = stripslashes(htmlspecialchars_decode(trim($data[$item])));
        }

        $data['url_original'] = $data['url'];

        $img = new \core\Images;
        $data = $img->render($data);
        unset($data['images']);

        $action = 'renderData' . ucfirst($type);
        return self::$action($data, $propertis) ? $data : false;
    }


    /**
     * @param $data
     * @param $propertis
     * @return bool
     */
    private function renderDataCategory(&$data, $propertis): bool
    {
        if ($propertis) {
            if (method_exists(\g::module('DynamicFields'), 'getFields')) {
                $data['properties'] = \g::module('DynamicFields')->getFields($data['templates'], 'category', $data['id']);
            }
        }

        $data['url'] = '/catalog/category/' . $data['url'] . '/';
        return true;
    }

    /**
     * @param $data
     * @param $url_render
     * @return void
     */
    public function RenderLangCategory(&$data, $url_render = true)
    {
        if (method_exists(\g::Multilingual(), 'getLangList')) {
            if (\g::multilingual()->checkRender()) {
                $lang = \g::stack()->get('lang');
                \g::multilingual()->createTableLangCatalog();
                $fills = ['name', 'name_abbr', 'title', 'keywords', 'description', 'anons', 'content'];
                $new_name = \g::db()->getRow(
                    "SELECT `name`, `name_abbr`, `title`, `keywords`,`description`,`anons`, `content` , `template`
                    FROM ?n 
                    WHERE `parent_id` = ?s AND `lang` = ?s",
                    'catalog_categories_lang',
                    $data['id'],
                    $lang);
                if ($new_name) {
                    foreach ($fills as $fill) {
                        $data[$fill] = $new_name[$fill];
                    }
                }

                if ($url_render) {
                    $data['url'] = $lang . "__" . $data['url'];
                }

            }
        }

    }

    /**
     * @param $data
     * @return void
     */
    public function RenderLangProducts(&$data)
    {
        if (method_exists(\g::Multilingual(), 'getLangList') && \g::stack()->get('lang') != 'ru') {
            if (\g::multilingual()->checkRender()) {
                $ids = [];
                foreach ($data as $product) {
                    $ids[] = $product['id'];
                }

                $all = \g::db()->getAll(
                    "SELECT `name`, `name_abbr`, `parent_id`, `title`, `anons`, `content` , `keywords`, `description` , `template`
                    FROM ?n 
                    WHERE `parent_id` IN (?a) AND `lang` = ?s",
                    'catalog_products_lang',
                    $ids,
                    \g::stack()->get('lang'));
                $render = [];
                foreach ($all as $el) {
                    $render[$el['parent_id']] = $el;
                }

                unset($all);
                $fills = ['name', 'name_abbr', 'title', 'anons', 'keywords', 'description', 'content'];
                foreach ($data as &$product) {
                    if (!array_key_exists($product['id'], $render))
                        continue;

                    foreach ($fills as $fill) {
                        $product[$fill] = $render[$product['id']][$fill];
                    }
                }
            }
        }
    }

    /**
     * @param $data
     * @param $url_render
     * @return void
     */
    public function RenderLangProduct(&$data, $url_render = true)
    {

        if (method_exists(\g::Multilingual(), 'getLangList')) {
            if (\g::multilingual()->checkRender()) {
                $lang = \g::stack()->get('lang');
                \g::multilingual()->createTableLangCatalog();
                $fills = ['name', 'name_abbr', 'title', 'anons', 'keywords', 'description', 'content'];
                $new_name = \g::db()->getRow(
                    "SELECT `name`, `name_abbr`, `title`, `anons`, `content` , `keywords`, `description` , `template`
                    FROM ?n 
                    WHERE `parent_id` = ?s AND `lang` = ?s",
                    'catalog_products_lang',
                    $data['id'],
                    $lang);
                if ($new_name) {
                    foreach ($fills as $fill) {
                        $data[$fill] = $new_name[$fill];
                    }
                }

                if ($url_render) {
                    $data['url'] = $lang . "__" . $data['url'];
                }
            }
        }

    }

    /**
     * @param $data
     * @param $propertis
     * @return bool
     */
    public function renderDataProduct(&$data, $propertis): bool
    {
        if ($propertis) {
            if (method_exists(\g::module('DynamicFields'), 'getFields')) {
                $data['properties'] = \g::module('DynamicFields')->getFields($data['templates'], 'product', $data['id']);
            }
        }
        $data['content'] = stripslashes(htmlspecialchars_decode($data['content']));
        $data['price_old'] = (is_numeric($data['price_old']) ? $data['price_old'] : 0);

        $data['price_old_format'] = $this->price_format($data['price_old']);

        $data['price'] = is_numeric($data['price']) ? $data['price'] : 0;

        if ($data['price'] > 0) {
            $data['price_format'] = $this->price_format($data['price']);
        } else {
            $data['price_format'] = $this->price_zero_value();
        }
        $data['url'] = '/catalog/product/' . $data['url'] . '/';

        if (!empty($data['modules'])) {
            foreach ($data['modules'] as $key => $module) {
                $img = new \core\Images;
                $data['modules'][$key] = $img->render($module);
                unset($data['modules'][$key]['images']);

                $data['modules'][$key]['url_original'] = $module['url'];
                $data['modules'][$key]['url'] = \f::generateUrl($module['url'], $module['prefix_url'], 'catalog');
            }
        }
        if ($data['last_update'] < 1000) {
            $data['last_update'] = 1609448400;
        }
        return true;
    }
}
