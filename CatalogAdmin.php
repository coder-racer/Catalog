<?php
ini_set('memory_limit', '1024M');
set_time_limit(999999999);

class CatalogAdmin
{
    /**
     * @var
     */
    public $time;

    /**
     * @var
     */
    private $book;
    /**
     * @var array
     */
    private $empty_products = [];
    /**
     * @var array
     */
    private $double_sku = [];
    /**
     * @var array
     */
    private $success_products = [];
    /**
     * @var array
     */
    private $errors = [];

    /**
     * @return true
     */
    public function searchCatAction()
    {
        \g::send([
            'res' => true,
            'data' => g::db()->getAll("SELECT * FROM `catalog_categories` WHERE name LIKE ?s", "%" . trim(\g::get("search")) . "%")
        ]);
        return true;
    }


    /**
     * @return true
     */
    public function getSearchStepAction()
    {
        $time = \g::settings('search_step');
        if (is_null($time)) {
            \g::db()->query("INSERT INTO `settings` ( `name`, `val`, `placeholder`, `description`, `label`, `type`, `modules`) VALUES
                    ('search_step', ?s, '', '', '', 'text', 'settings');", '9');
        }
        \g::response()->data()->set(['res' => is_null($time) ? 0 : $time]);
        return true;
    }

    /**
     * @param $id
     * @return void
     */
    private function disableCategory($id)
    {
        \g::db()->query(
            "UPDATE catalog_products
             SET active = 0 
             WHERE id IN(SELECT cid FROM catalog_relations WHERE type = 1 AND pid = ?s)", $id
        );

        \g::db()->query(
            "UPDATE catalog_categories
             SET active = 0 
             WHERE id IN(SELECT cid FROM catalog_relations WHERE type = 2 AND pid = ?s)", $id
        );

        $cats = \g::db()->getCol("SELECT cid FROM catalog_relations WHERE type = 2 AND pid = ?s", $id);
        foreach ($cats as $id_cat) {
            $this->disableCategory($id_cat);
        }
    }

    /**
     * @return true
     */
    public function changeSearchStepAction()
    {
        \g::db()->query("UPDATE `settings` SET val = ?s WHERE name = 'search_step'", \g::request()->post()->get('value'));
        \g::response()->data()->set(['res' => true, 'data' => \g::request()->post()->get('value') ? '0' : 1]);
        return true;
    }

    /**
     * @return true
     */
    public function getCountAction()
    {
        $time = \g::settings('round_catalog');
        if (is_null($time)) {
            \g::db()->query("INSERT INTO `settings` ( `name`, `val`, `placeholder`, `description`, `label`, `type`, `modules`) VALUES
                    ('round_catalog', ?s, '', '', '', 'text', 'settings');", '2');
        }
        \g::response()->data()->set(['res' => is_null($time) ? 0 : $time]);
        return true;
    }

    /**
     * @return true
     */
    public function changeCountAction()
    {
        \g::db()->query("UPDATE `settings` SET val = ?s WHERE name = 'round_catalog'", \g::request()->post()->get('value'));
        \g::response()->data()->set(['res' => true, 'data' => \g::request()->post()->get('value') ? '0' : 1]);
        return true;
    }

    /**
     * @return true
     */
    public function importExcelAction()
    {
        set_time_limit(0);

        $this->time_init();

        if (isset($_FILES['import_file']['tmp_name'])) {
            $post = \g::request()->post()->get();

            if (isset($post['input_articul'])
                && isset($post['input_price'])
                && isset($post['input_stock'])
                && isset($post['prefix'])
                && isset($post['trim'])
                && isset($post['input_price_old'])) {
                $file = time() . rand(100, 999) . '_' . $_FILES['import_file']['name'];
                copy($_FILES['import_file']['tmp_name'], ROOT . '/' . $file);

                $articul = intval($post['input_articul']);
                $prefix = ($post['prefix']);
                $trim = intval($post['trim']) == 1;

                $price = floatval($post['input_price']);
                $price_old = floatval($post['input_price_old']);
                $stock = floatval($post['input_stock']);

                $status = $this->startImport($file, $articul, $price, $price_old, $stock, $prefix, $trim);

                unlink($file);

                foreach ($this->double_sku as $item) {
                    $this->updateProductDoubleSku(
                        $item['articul'],
                        $item['price'],
                        $item['price_old'],
                        $item['stock']
                    );
                }

                // HW
                if ($this->success_products[0]) {
                    $countAll = \g::db()->getOne("SELECT count(id) FROM catalog_products WHERE not sku in(?a)", $this->success_products);
                } else {
                    $countAll = \g::db()->getOne("SELECT count(id) FROM catalog_products");
                }

                \g::response()->data()->set([
                    'res' => $status,
                    'errors' => $this->errors,
                    'success' => $this->success_products,
                    'not_update' => $countAll,
                    'empty' => $this->empty_products,
                    'time' => $this->check_time(),
                ]);
            } else {
                \g::response()->data()->set([
                    'res' => false,
                    'errors' => ['Ошибка параметров запроса']
                ]);
            }
        } else {
            \g::response()->data()->set([
                'res' => false,
                'errors' => ['Файл не выбран']
            ]);
        }

        return true;
    }

    /**
     * @return true
     */
    public function changeSkuAction()
    {
        \g::db()->query("UPDATE `settings` SET val = ?s WHERE name = 'catalog_sku'", \g::request()->post()->get('change'));
        \g::response()->data()->set([
            'res' => false,
            'errors' => \g::request()->post()->get('change')
        ]);
        return true;
    }

    /**
     * @return true
     */
    public function changeStockAction()
    {
        \g::db()->query("UPDATE `settings` SET val = ?s WHERE name = 'stock_on'", \g::request()->post()->get('change'));
        \g::response()->data()->set([
            'res' => false,
            'errors' => \g::request()->post()->get('change')
        ]);
        return true;
    }

    /**
     * @param $file
     * @param $articul
     * @param $price
     * @param $price_old
     * @param $stock
     * @param $prefix
     * @param $trim
     * @return bool
     */
    public function startImport($file, $articul = 1, $price = 0, $price_old = 0, $stock = 0, $prefix = "", $trim)
    {
        try {
            $this->loadFile($file);
        } catch (\Throwable $e) {
            $this->errors[] = 'Ошибка загрузки файла';
            return false;
        }

        $height = $this->maxHeight();
        $width = $this->maxWidth();

        if ($articul > $width || $price > $width || $price_old > $width || $stock > $width) {
            $this->errors[] = 'Индекс за пределами диапазона';
            return false;
        }

        for ($i = 1; $i <= $height; $i++) {
            try {
                $cell_articul = $this->getCell($articul, $i);
                if ($trim) {
                    $cell_articul = $prefix . str_replace(" ", '', $cell_articul);
                }
                $cell_price = $price > 0 ? $this->getCell($price, $i) : false;
                $cell_price_old = $price_old > 0 ? $this->getCell($price_old, $i) : false;
                $cell_stock = $price_old > 0 ? $this->getCell($stock, $i) : false;

                if (!$cell_articul) continue;

                $info[] = $this->updateProduct($cell_articul, $cell_price, $cell_price_old, $cell_stock);
            } catch (\Throwable $e) {
                $this->errors[] = 'Ошибка проведения операции';
                return false;
            }
        }

        return true;
    }

    /**
     * @param $articul
     * @param $price
     * @param $price_old
     * @param $stock
     * @return true
     */
    public function updateProduct($articul, $price, $price_old = 0, $stock = 0)
    {
        $sql = [];
        $none = true;

        if ($price > 0) {
            $sql[] = "price = '$price'";
            $none = false;
        }

        if ($price_old > 0) {
            if (strlen($sql) > 4) {
                $sql .= ", ";
            }
            $sql[] = "price_old = '$price_old'";
            $none = false;
        }

        if ($stock > 0) {
            if (strlen($sql) > 4) {
                $sql .= ", ";
            }
            $sql[] = "stock = '$stock'";
            $none = false;
        }

        if ($none) {
            // \g::db()->query('UPDATE catalog_products SET price = price WHERE sku = ?s', trim($articul));
        } else {
            \g::db()->query('UPDATE catalog_products SET ' . implode(',', $sql) . ' WHERE sku = ?s', trim($articul));
        }

        if ($this->matchedRows() > 0) {
            $this->success_products[] = trim($articul);
        } else {
            $this->double_sku[] = [
                'articul' => $articul,
                'price' => $price,
                'price_old' => $price_old,
                'stock' => $stock
            ];
        }

        return true;
    }

    /**
     * @param $articul
     * @param $price
     * @param $price_old
     * @param $stock
     * @return true
     */
    public function updateProductDoubleSku($articul, $price, $price_old = 0, $stock = 0)
    {

        $sql = "";
        $none = true;

        if ($price > 0) {
            $sql .= "price = price + '$price'";
            $none = false;
        }

        if ($price_old > 0) {
            if (strlen($sql) > 4) {
                $sql .= ", ";
            }
            $sql .= "price_old = '$price_old'";
            $none = false;
        }

        if ($stock > 0) {
            if (strlen($sql) > 4) {
                $sql .= ", ";
            }
            $sql .= "stock = '$stock'";
            $none = false;
        }

        if ($none) {
            // \g::db()->query('UPDATE catalog_products SET price = price WHERE double_sku = ?s', trim($articul));
        } else {
            \g::db()->query('UPDATE catalog_products SET ' . $sql . ' WHERE double_sku = ?s', trim($articul));
        }

        if ($this->matchedRows() > 0) {
            $this->success_products[] = trim($articul);
        } else {
            $this->empty_products[] = trim($articul);
        }

        return true;
    }

    /**
     * @param $col
     * @param $row
     * @return false
     */
    public function getCell($col, $row)
    {
        $cell = $this->book->getCellByColumnAndRow($col - 1, $row);
        $val = $cell->getValue();

        return $val == null ? false : $val;
    }

    /**
     * @param $file
     * @return void
     * @throws PHPExcel_Exception
     * @throws PHPExcel_Reader_Exception
     */
    public function loadFile($file)
    {
        include_once DIR . '/miwix-admin/vendors/PHPExcel.php';

        $objPHPexcel = \PHPExcel_IOFactory::load($file);
        $objWorksheet = $objPHPexcel->getActiveSheet();
        $this->book = $objWorksheet;
    }

    /**
     * @return mixed
     */
    public function maxHeight()
    {
        return $this->book->getHighestRow();
    }

    /**
     * @param $char
     * @return float|int
     * @throws PHPExcel_Exception
     */
    public function maxWidth($char = false)
    {
        $highestColumn = $this->book->getHighestColumn();
        if ($char) return $highestColumn;
        return PHPExcel_Cell::columnIndexFromString($highestColumn); //stringFromColumnIndex
    }

    /**
     * @return int
     */
    public function matchedRows()
    {
        preg_match('/Rows matched:\s+(\d+)/', \g::db()->conn->info, $matched);
        return isset($matched[1]) ? intval($matched[1]) : 0;
    }

    /**
     * @return void
     */
    function time_init()
    {
        $this->time = microtime(true);
    }

    /**
     * @return float
     */
    function check_time()
    {
        $time = microtime(true) - $this->time;
        $this->time_init();
        return $time;
    }

    /**
     *
     */
    public function __construct()
    {
        require_once dirname(__DIR__) . '/classes/Catalog.php';
        require_once dirname(__DIR__) . '/classes/CatalogUtils.php';

//        $check = \g::db()->getRow("CHECK TABLE catalog_sku_list")['Msg_text'];
//
//        if ($check != "OK") {
//            \g::db()->query("
//                CREATE TABLE `catalog_sku_list` (
//                  `id` int(11) AUTO_INCREMENT,
//                  `parent_id` int(11) NOT NULL,
//                  `sku` varchar(100) NOT NULL,
//                   UNIQUE (id)
//                );
//            ");
//        }
    }

    /**
     * @return bool
     */
    public function getOriginalSku()
    {
        $time = \g::settings('catalog_sku');
        if (strlen($time) < 1) {
            \g::db()->query("INSERT INTO `settings` ( `name`, `val`, `placeholder`, `description`, `label`, `type`, `modules`) VALUES
                    ('catalog_sku', ?s, '', '', '', 'text', 'settings');", 1);
            return true;
        }
        return (bool)$time;
    }

    /**
     * @return bool
     */
    public function getOriginalStock()
    {
        $time = \g::settings('stock_on');
        if (is_null($time)) {
            \g::db()->query("INSERT INTO `settings` ( `name`, `val`, `placeholder`, `description`, `label`, `type`, `modules`) VALUES
                    ('stock_on', ?s, '', '', '', 'text', 'settings');", 0);
            return false;
        }
        return (bool)$time;
    }

    /**
     * @return true
     */
    public function getSkuCheckAction()
    {
        $data = (bool)$this->getOriginalSku();
        \g::response()->data()->set(['res' => true, 'data' => $data, 'stock' => $this->getOriginalStock()]);
        return true;
    }

    /**
     * @param $str
     * @return array|string|string[]|null
     */
    private function filterDataStr(&$str)
    {
        $str = preg_replace("/\t/", "\\t", $str);
        $str = preg_replace("/\r?\n/", "\\n", $str);
        if (strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
        return $str;
    }

    /**
     * @return true
     * @throws PHPExcel_Exception
     * @throws PHPExcel_Writer_Exception
     */
    public function exportProductsExelNotSkuAction()
    {
        include_once DIR . '/miwix-admin/vendors/PHPExcel.php';
        $xls = new PHPExcel();
        $xls->setActiveSheetIndex(0);
        $sheet = $xls->getActiveSheet();
        $sheet->setTitle('Лист 1');
        $tmpFileName = "temp/export_products_exel_not_updated.xlsx";
        $sku = explode(',', \g::request()->post()->get('sku'));

        $allFields = [
            'id',
            'url',
            'name',
            'name_abbr',
            'title',
            'keywords',
            'description',
            'price',
            'price_old',
            'sku',
            'stock'
        ];

        $allFieldsDescription = [
            'id' => 'id',
            'url' => 'URL',
            'name' => 'Название страницы (H1)',
            'name_abbr' => 'Сокращённое название',
            'title' => 'Заголовок (meta:title)',
            'keywords' => 'Ключевые слова (meta:keywords)',
            'description' => 'Описание (meta:description)',
            'price' => 'Цена',
            'price_old' => 'Старая Цена',
            'sku' => 'Артикуль',
            'stock' => 'Остаток на складе'
        ];


        $sheet->fromArray($allFieldsDescription, NULL, "A1");
        $sheet->fromArray(\g::db()->getAll("SELECT " . implode(',', $allFields) . "
			FROM `catalog_products` 
			WHERE NOT sku IN (?a) AND `active` = 1", $sku), NULL, "A2");

        $objWriter = new PHPExcel_Writer_Excel2007($xls);
        $objWriter->save($tmpFileName);

        \g::response()->data()->set(['res' => true, 'url_file' => $tmpFileName]);
        return true;
    }


    /**
     * @return void
     * @throws PHPExcel_Exception
     * @throws PHPExcel_Writer_Exception
     */
    public function exportProductsExelAction()
    {
        include_once DIR . '/miwix-admin/vendors/PHPExcel.php';
        $xls = new PHPExcel();
        $xls->setActiveSheetIndex(0);
        $sheet = $xls->getActiveSheet();
        $sheet->setTitle('Лист 1');

        $param = \g::request()->get()->get('param');
        $param = json_decode(base64_decode($param));

        $allFields = [
            'id',
            'url',
            'name',
            'name_abbr',
            'title',
            'keywords',
            'description',
            'price',
            'price_old',
            'sku',
            'stock'
        ];

        $allFieldsDescription = [
            'id' => 'id',
            'url' => 'URL',
            'name' => 'Название страницы (H1)',
            'name_abbr' => 'Сокращённое название',
            'title' => 'Заголовок (meta:title)',
            'keywords' => 'Ключевые слова (meta:keywords)',
            'description' => 'Описание (meta:description)',
            'price' => 'Цена',
            'price_old' => 'Старая Цена',
            'sku' => 'Артикуль',
            'stock' => 'Остаток на складе'
        ];

        $fields = [];
        $fieldsDescription = [];

        foreach ($param as $item) {
            if (in_array($item, $allFields)) {
                $fields[] = $item;
            }
        }
        $ColumnsNames = [];

        foreach ($fields as $field) {
            $ColumnsNames[] = $allFieldsDescription[$field];
        }


        $sheet->fromArray($ColumnsNames, NULL, "A1");
        $sheet->fromArray(\g::db()->getAll("SELECT " . implode(',', $fields) . " FROM `catalog_products` WHERE `active` = 1"), NULL, "A2");


        header("Expires: Mon, 1 Apr 1974 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D,d M YH:i:s") . " GMT");
        header("Cache-Control: no-cache, must-revalidate");
        header("Pragma: no-cache");
        header("Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment; filename=products-" . date('dmY-His') . ".xlsx");

        $objWriter = new PHPExcel_Writer_Excel2007($xls);
        $objWriter->save('php://output');
        exit();
    }

    /**
     * @return void
     */
    public function exportProductsAction()
    {
        $param = \g::request()->get()->get('param');
        $param = json_decode(base64_decode($param));

        $allFields = [
            'id',
            'url',
            'name',
            'name_abbr',
            'title',
            'keywords',
            'description',
            'price',
            'price_old',
            'sku',
            'stock'
        ];

        $allFieldsDescription = [
            'id' => 'id',
            'url' => 'URL',
            'name' => 'Название страницы (H1)',
            'name_abbr' => 'Сокращённое название',
            'title' => 'Заголовок (meta:title)',
            'keywords' => 'Ключевые слова (meta:keywords)',
            'description' => 'Описание (meta:description)',
            'price' => 'Цена',
            'price_old' => 'Старая Цена',
            'sku' => 'Артикуль',
            'stock' => 'Остаток на складе'
        ];

        $fields = [];

        foreach ($param as $item) {
            if (in_array($item, $allFields)) {
                $fields[] = $item;
            }
        }

        $fieldsDescription = [];

        foreach ($fields as $item) {
            $fieldsDescription[] = $allFieldsDescription[$item];
        }


        header('Content-Encoding: UTF-8');
        header('Content-Type: text/csv; charset=utf-8');
        header(sprintf('Content-Disposition: attachment; filename=products-%s.csv', date('dmY-His')));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');

        $df = fopen('php://output', 'w');

        fputs($df, "\xEF\xBB\xBF");

        fputcsv($df, $fieldsDescription, ';');
        $products = \g::db()->getAll("SELECT * FROM `catalog_products` WHERE `active` = 1");
        foreach ($products as $row) {
            $data = [];

            foreach ($fields as $fieldName) {
                $data[] = $row[$fieldName];
            }
            fputcsv($df, $data, ';');
        }
        fclose($df);
        die;
    }

    /**
     * @return true
     */
    public function getDisableElAction()
    {
        $type = \g::request()->post()->get('type');
        $table = 'catalog_categories';

        if ($type == 'product')
            $table = 'catalog_products';

        $data = \g::db()->getAll('SELECT * FROM ?n WHERE active = 0', $table);
        \g::response()->data()->set(['res' => true, 'data' => $data]);
        return true;
    }

    /**
     * @return true
     */
    public function getPopularFilterNamesAction()
    {
        $name = \g::request()->post()->get('name');
        if ($name) {
            $name = "%" . $name;
            $name .= "%";
            $data = \g::db()->getAll("
                SELECT DISTINCT name, COUNT(*) AS 'counts' FROM `catalog_filters`
                WHERE name LIKE ?s
                GROUP BY name
                HAVING COUNT(*) > 1
                ORDER BY counts desc
                LIMIT 5
            ", $name);
        } else {
            $data = \g::db()->getAll("SELECT DISTINCT name, COUNT(*) AS 'counts' FROM `catalog_filters` WHERE 1
                                        GROUP BY name
                                        HAVING COUNT(*) > 1
                                        ORDER BY counts desc
                                        LIMIT 5");
        }

        \g::response()->data()->set(['res' => true, 'data' => $data]);
        return true;
    }

    /**
     * @return true
     */
    public function getPopularFilterValuesAction()
    {
        $name = \g::request()->post()->get('name');
        $value = \g::request()->post()->get('value');
        /** @var TYPE_NAME $name */
        if ($value && $name) {
            $name = "%" . $name;
            $name .= "%";
            $value = "%" . $value;
            $value .= "%";
            $data = \g::db()->getAll("
                SELECT DISTINCT value, COUNT(*) AS 'counts' FROM `catalog_filters`
                WHERE name LIKE ?s AND value LIKE ?s
                GROUP BY value
                HAVING COUNT(*) > 0
                ORDER BY counts desc
                LIMIT 5
            ", $name, $value);
        } else if ($name) {
            $name = "%" . $name;
            $name .= "%";
            $data = \g::db()->getAll("
                SELECT DISTINCT value, COUNT(*) AS 'counts' FROM `catalog_filters`
                WHERE name LIKE ?s
                GROUP BY value
                HAVING COUNT(*) > 0
                ORDER BY counts desc
                LIMIT 5
            ", $name);
        } else {
            $data = \g::db()->getAll("
                SELECT DISTINCT value, COUNT(*) AS 'counts' FROM `catalog_filters`
                GROUP BY value
                HAVING COUNT(*) > 0
                ORDER BY counts desc
                LIMIT 5
            ");
        }

        \g::response()->data()->set(['res' => true, 'data' => $data]);
        return true;
    }

    /**
     * @param $id
     * @return void
     */
    public function deleteProductAction($id = -1)
    {
        if ($id == -1)
            $id = \g::request()->post()->get('id');
        \g::db()->query("DELETE FROM `catalog_products` WHERE `id` = ?i", $id);
        \g::db()->query("DELETE FROM `catalog_relations` WHERE `cid` = ?i AND `type` = 1", $id);
        \g::db()->query("DELETE FROM `catalog_filters` WHERE `parent_id` = ?i", $id);
        \g::base()->generateSiteMap();
    }

    /**
     * @return void
     */
    public function UploadImageProductAction()
    {
        \g::Files()->updateImage('files/catalog/products');
    }

    /**
     * @return void
     */
    public function UploadImagePageAction()
    {
        \g::Files()->updateImage('files/pages');
    }

    /**
     * @return void
     */
    public function UploadImageСategoriesAction()
    {
        \g::Files()->updateImage('files/catalog/categories');
    }

    /**
     * @param $id
     * @return void
     */
    public function deleteCategoriesAction($id = -1)
    {
        if ($id == -1)
            $id = \g::request()->post()->get('id');
        $ids = \g::Catalog()->getIdCategoriesForParent($id);
        \g::db()->query("DELETE FROM `catalog_categories` WHERE `id` IN ( ?a )", $ids);
        \g::db()->query("DELETE FROM `catalog_relations` WHERE `cid` IN ( ?a ) AND `type` = 2", $ids);
		
        $products = \g::db()->getCol("SELECT cid FROM `catalog_relations` WHERE `pid` IN ( ?a ) AND `type` = 1", $ids);
		
		$del_product_img = \g::request()->post()->get('del_product_img');
		$allImg = [];
		
		if ($del_product_img) {
			$dopInfo = \g::db()->getAll("SELECT id,images FROM `catalog_products` WHERE id IN(?a)",$products);
			
			foreach ($dopInfo as &$dataElem) {
				$allImg = array_merge(explode('|',$dataElem['images']),$allImg);
			}
		}
		
		if($allImg){$this->deleteAllImages($allImg);}
		
		
        \g::db()->query("DELETE FROM `catalog_products` WHERE `id` in (?a)", $products);
        \g::db()->query("DELETE FROM `catalog_relations` WHERE `cid` in(?a) AND `type` = 1", $products);
        \g::db()->query("DELETE FROM `catalog_filters` WHERE `parent_id` in (?a)", $products);
        \g::base()->generateSiteMap();
    }

    /**
     * @return array|array[]
     */
    public function getCatalogInfo()
    {
        $result = [
            "category" => [
                "active" => 0,
                "no_active" => 0
            ],
            "product" => [
                "active" => 0,
                "no_active" => 0
            ],
        ];
        $query = \g::db()->query("SELECT COUNT(`id`) FROM `catalog_products` WHERE `active` = 1");
        if ($query->num_rows) {
            $result['product']['active'] = $query->fetch_row()[0];
        }
        $query = \g::db()->query("SELECT COUNT(`id`) FROM `catalog_products` WHERE `active` = 0");
        if ($query->num_rows) {
            $result['product']['no_active'] = $query->fetch_row()[0];
        }
        $query = \g::db()->query("SELECT COUNT(`id`) FROM `catalog_categories` WHERE `active` = 1");
        if ($query->num_rows) {
            $result['category']['active'] = $query->fetch_row()[0];
        }
        $query = \g::db()->query("SELECT COUNT(`id`) FROM `catalog_categories` WHERE `active` = 0");
        if ($query->num_rows) {
            $result['category']['no_active'] = $query->fetch_row()[0];
        }

        return $result;
    }

    /**
     * @param $post
     * @return bool
     */
    public function createProductAction($post = -1)
    {
        $post_json = false;
        if ($post == -1) {
            $post = \g::request()->post()->get();
            $post_json = true;
        }
        $fieldCheck = ['url', 'name', 'title', 'name_abbr', 'keywords', 'description'];
        foreach ($fieldCheck as $item) {
            if (empty($post[$item])) {
                \g::response()->data()->set(['res' => false, 'message' => "Заполните все поля"]);
                return false;
            }
        }

        $sku_list = (array)json_decode(html_entity_decode(stripslashes(\g::request()->post()->get("sku_list"))), true);

        $sku_all_list = $sku_list;

        $sku_all_list[] = $post['sku'];

        $info = \g::db()->getAll("
                SELECT COUNT(*) as 'count' FROM `catalog_products` WHERE url = ?s
                UNION ALL
                SELECT COUNT(*) as 'count' FROM `catalog_products` WHERE sku in(?a)
                UNION ALL
                SELECT COUNT(*) as 'count' FROM `catalog_sku_list` WHERE sku in(?a)
                ", $post['url'], $sku_all_list, $sku_all_list);

        if ($info[0]['count'] > 0) {
            \g::response()->data()->set(['res' => false, 'message' => "URL должен быть уникальным"]);
            return false;
        }

        if ($this->getOriginalSku()) {
            if ($info[1]['count'] > 0) {
                if (strlen($post['sku']) > 0) {
                    \g::response()->data()->set(['res' => false, 'message' => "Артикуль должен быть уникальным"]);
                    return false;
                }
            }

            if ($info[2]['count'] > 0) {
                if (strlen($post['sku']) > 0) {
                    \g::response()->data()->set(['res' => false, 'message' => "Артикуль должен быть уникальным"]);
                    return false;
                }
            }
        }

        $post['parent_id'] = explode(",", $post['parent_id']);
        $query = \g::db()->query("
			INSERT INTO `catalog_products`(
				`name_abbr`,`stock`,`keywords`,`description`, `sort_nav`,`is_nav` ,`nav_parent_url`,`url`, `name`, `sort`, `title`, `content`, `templates`, `images`, `time_creation`, `active`, `price`, `price_old`, `sku`, `double_sku`, `last_modified`, `site_map`
			) VALUES (
				?s,
				?i,
				?s,
				?s,
				?s,
				?s,
				?s,
				?s,
				?s,
				?i,
				?s,
				?s,
				?s,
				?s,
				?i,
				?i,
				?i,
				?i,
				?s,
				?s,
				?i,
			    ?i
			);",
             $post['name_abbr'],
            intval($post['stock'] ?? 0),
            $post['keywords'] ?? "",
            $post['description'] ?? "",
            intval($post['sort_nav']),
            intval($post['is_nav']),
            $post['nav_parent_url'],
            $post['url'],
            $post['name'],
            intval($post['sort']),
            $post['title'],
            $post['content'] ?? "",
            $post['templates'] ?? "",
            $post['images'] ?? "",
            time(),
            intval($post['active']),
            floatval($post['price']),
            floatval($post['price_old']),
            $post['sku'] ?? "",
            $post['double_sku'] ?? "",
            time(),
            intval($post['site_map'])
        );

        $id = \g::db()->insertId();
        foreach ($post['parent_id'] as $parent) {
            $query = \g::db()->query("
			INSERT INTO `catalog_relations`(
				`cid`, `pid`, `type`
			) VALUES (
				?i,
				?i,
				?i
			);", $id, $parent, 1);
        }
        $filters = json_decode(html_entity_decode(stripslashes(\g::request()->post()->get("filters"))), true);
        foreach ($filters as $filter) {
            $query = \g::db()->query("
                INSERT INTO `catalog_filters`(
                    `parent_id`, `name`, `value`, `filter`
                ) VALUES (
                    ?i,
                    ?s,
                    ?s,
                    ?i
                );", $id, $filter['name'], $filter['value'], $filter['filter'] ? 1 : 0);
        }


        foreach ($sku_list as $sku) {
            $query = \g::db()->query("
                INSERT INTO `catalog_sku_list`(
                    `parent_id`, `sku`
                ) VALUES (
                    ?i,
                    ?s
                );", $id, $sku);
        }

        \g::base()->generateSiteMap();
        if ($post_json) {
            \g::response()->data()->set(['res' => true, 'success' => true, 'message' => "Изменения успешно сохранены", 'id' => $id]);
            return true;
        }
        return true;
    }

    /**
     * @param $post
     * @return bool
     */
    public function createCategoriesAction($post = -1)
    {
        if ($post == -1)
            $post = \g::request()->post()->get();

        $info = \g::db()->getOne("
                SELECT COUNT(*) as 'count' FROM `catalog_categories` WHERE url = ?s
                ", $post['url']);

        if ($info > 0) {
            \g::response()->data()->set(['res' => false, 'message' => "URL должен быть уникальным"]);
            return false;
        }
        $fieldCheck = ['url', 'name', 'title', 'name_abbr', 'keywords', 'description'];
        foreach ($fieldCheck as $item) {
            if (empty($post[$item])) {
                \g::response()->data()->set(['res' => false, 'success' => false, 'message' => "Заполните все поля"]);
                return false;
            }
        }
        $post['parent_id'] = explode(",", $post['parent_id']);
        $query = \g::db()->query("
			INSERT INTO `catalog_categories`(
				`name_abbr`,`keywords`,`description`, `images`,`nav_parent_url`,`sort`,`url`,`content`, `name`, `title`, `time_creation`, `active`, `last_modified`, `is_nav`, `sort_nav`, `site_map`
			) VALUES (
				?s,
				?s,
				?s,
				?s,
				?s,
				?i,
				?s,
				?s,
				?s,
				?s,
				?i,
				?i,
				?i,
			    ?i,
			    ?i,
			    ?i
			)",
            $post['name_abbr'] ?? "",
            $post['keywords'] ?? "",
            $post['description'] ?? "",
            $post['images'] ?? "",
            $post['nav_parent_url'],
            $post['sort'],
            $post['url'],
            $post['content'],
            $post['name'],
            $post['title'],
            time(),
            intval($post['active']),
            time(),
            intval($post['is_nav']),
            intval($post['sort_nav']),
            intval($post['site_map']),
        );
        $id = \g::db()->insertId();
        foreach ($post['parent_id'] as $parent) {
            $query = \g::db()->query("
			INSERT INTO `catalog_relations`(
				`cid`, `pid`, `type`
			) VALUES (
				?i,
				?i,
				?i
			);", $id, $parent, 2);
        }
        \g::base()->generateSiteMap();
        \g::response()->data()->set(['res' => false, 'success' => true, 'message' => "Изменения успешно сохранены", 'id' => $id]);
        return true;
    }

    /**
     * @return array|false
     */
    public function getAllCategoriesAction()
    {
        $data = \g::db()->getAll("SELECT * FROM `catalog_categories`");
        return empty($data) ? false : $data;
    }

    /**
     * @return array
     */
    public function getAllProduct()
    {
        $new = [];
        $data = \g::db()->getAll("SELECT * FROM `catalog_products` WHERE `active` = ?i", intval(1));
        foreach ($data as $value) {
            $new[$value['parent_id']][] = $value;
        }
        return $new;

    }

    /**
     * @param $id
     * @return array|FALSE
     */
    public function getProductForIdAction($id)
    {
        $data = \g::db()->getRow("SELECT g.* FROM `catalog_products` g WHERE g.id = ?i", intval($id)) ?? false;

        if ($data) {
            $data['parent'] = \g::db()->getAll("SELECT cr.pid as 'id', cc.name FROM `catalog_relations` cr LEFT JOIN `catalog_categories` as cc ON cr.pid = cc.id WHERE cid = ?i AND type = 1", $id);
            foreach ($data['parent'] as &$item) {
                if ($item['id'] == '0')
                    $item['name'] = 'Корень';

            }
            $data['price'] = str_replace(",", ".", $data['price']);
        }
        \g::CatalogUtils()->RenderLangProduct($data, false);

        $data['filters'] = \g::db()->getAll("SELECT name, value, id, filter FROM `catalog_filters` WHERE parent_id = ?i", $id);

        $data['sku_list'] = \g::db()->getCol("SELECT sku FROM `catalog_sku_list` WHERE  parent_id = ?i", $id);
        return $data;
    }

    /**
     * @param $id
     * @return array|bool
     */
    public function getCategoriesForIdAction($id = -1)
    {
        if ($id == -1)
            $id = \g::request()->post()->get('id');
        $result = \g::db()->getRow("SELECT * FROM `catalog_categories` WHERE id = ?i", intval($id)) ?? false;
        if ($result) {
            $result['parent'] = \g::db()->getAll("SELECT cr.pid as 'id', cc.name FROM `catalog_relations` cr LEFT JOIN `catalog_categories` as cc ON cr.pid = cc.id WHERE cid = ?i AND type = 2", $id);
            foreach ($result['parent'] as &$item) {
                if ($item['id'] == '0')
                    $item['name'] = 'Корень';

            }
        }
        if (method_exists(\g::Multilingual(), 'getLangList')) {
            \g::CatalogUtils()->RenderLangCategory($result, false);
        }
        if ($id == -1) {
            \g::response()->data()->set(['res' => false, 'data' => $result]);
            return true;
        }
        return $result;
    }

    /**
     * @param $id
     * @return array|bool
     */
    public function getCategoriesForParentIdAction($id = -1)
    {
        $post = false;
        if ($id == -1) {
            $id = \g::request()->post()->get('id');
            $post = true;
        }
        $result = \g::db()->getAll("SELECT c.*, r.pid as 'parent' FROM `catalog_categories` c LEFT JOIN `catalog_relations` r ON c.id = r.cid WHERE r.`type` = 2 AND r.`pid` = ?i
		ORDER BY `LAST_MODIFIED` DESC;", intval($id)) ?? false;

        foreach ($result as &$item) {
            $item['type'] = 'none';
//            if (\g::db()->query("SELECT COUNT(*) FROM `catalog_relations` WHERE pid = ?i AND type = 2", $item['id'])->fetch_row()[0] > 0) {
//                $item['type'] = 'category';
//            }
//            if (\g::db()->query("SELECT COUNT(*) FROM `catalog_relations` WHERE pid = ?i AND type = 1", $item['id'])->fetch_row()[0] > 0) {
//                $item['type'] = 'product';
//            }
        }
        if ($post) {
            \g::response()->data()->set(['res' => false, 'data' => $result]);
            return true;
        }
        return $result;
    }

    /**
     * @param $id
     * @return array|bool
     */
    public function getProductForParentIdAction($id = -1)
    {
        $post = false;
        if ($id == -1) {
            $id = \g::request()->post()->get('id');
            $post = true;
        }
        $result = \g::db()->getAll("SELECT c.*, r.pid as 'parent' FROM `catalog_products` c LEFT JOIN `catalog_relations` r ON c.id = r.cid WHERE r.`type` = 1 AND r.`pid` = ?i ORDER BY `LAST_MODIFIED` DESC;", intval($id)) ?? false;

        $result = empty($result) ? false : $result;

        if ($post) {
            \g::response()->data()->set(['res' => false, 'data' => $result]);
            return true;
        }
        return $result;

    }

    /**
     * @return void
     */
    public function generateStocks()
    {
        ini_set('upload_max_size', '64M');
        ini_set('post_max_size', '64M');
        ini_set('max_execution_time', '300');
        set_time_limit(999999999);
        \g::db()->query("UPDATE catalog_products SET stock = RAND()*(734-316)+316 WHERE active = 1 AND price > 0");
    }

    /**
     * @return true
     */
    public function relocateCatalogListAction()
    {
        $list = \g::request()->post()->get('list');
        $list = json_decode(stripslashes(html_entity_decode($list)));
        $parent = \g::request()->post()->get('parent');
        if (count($list) < 1) {
            \g::response()->data()->set(['res' => false, 'message' => 'Элементы для перемещения не выбраны']);
            return true;
        }
        if (\g::request()->post()->get('type') == "p")
            \g::db()->query("UPDATE catalog_relations SET pid = ?i WHERE `type` = 1 AND `cid` in(?a)", $parent, $list);
        else
            \g::db()->query("UPDATE catalog_relations SET pid = ?i WHERE `type` = 2 AND `cid` in(?a)", $parent, $list);
        \g::response()->data()->set(['res' => true, 'message' => 'Перемещение прошло успешно']);
        return true;
    }

    /**
     * @return true
     */
    public function deleteListProductsAction()
    {
        $list = \g::request()->post()->get('list');
        $list = json_decode(stripslashes(html_entity_decode($list)));
        $del_all_img = \g::request()->post()->get('del_all_img');
        $del_product_img = \g::request()->post()->get('del_product_img');
		$allImg = [];
		
		if ($del_product_img) {
			$dopInfo = \g::db()->getAll("SELECT id,images FROM `catalog_products` WHERE id IN(?a)",$list);
			
			foreach ($dopInfo as &$dataElem) {
				$allImg = array_merge(explode('|',$dataElem['images']),$allImg);
			}
		}
			
		if ($del_all_img) {
			$dopInfo = \g::db()->getAll("SELECT id,images,content FROM `catalog_products` WHERE id IN(?a)",$list);
			$allContent = '';
			$pregMatch = [];
			$allImg = [];
			
			foreach ($dopInfo as &$dataElem) {
				$allContent.= stripcslashes(html_entity_decode($dataElem['content']));
				$allImg = array_merge(explode('|',$dataElem['images']),$allImg);
			}

			preg_match_all("/(([a-zA-Z0-9_ \-%\/.]*)\.(jpg|png|jpeg|gif|webp))/m", $allContent, $pregMatch);
			$allImg = array_merge($allImg,$pregMatch[0]);
		}
		
		if($allImg){$this->deleteAllImages($allImg);}
		
		if (count($list) < 1) {
			\g::response()->data()->set(['res' => false, 'message' => 'Элементы для удаления не выбраны']);
			return true;
		}

		foreach ($list as $item) {
			$this->deleteProductAction($item);
		}
		
        \g::response()->data()->set(['res' => true, 'message' => 'Удаление прошло успешно', 
			'allContent' => $allContent,
			'allImg' => $allImg,
			'pregMatch' => $pregMatch,
		]);
        return true;
    }
	
	// Удалит все картинки включая миниатюры

    /**
     * @param $allImg
     * @return void
     */
    public function deleteAllImages ($allImg){
		$ignoreFoldersNoSlash = ['big','icon','mid','small','up_mid'];
		
		foreach ($allImg as $item) {
		   $item = str_replace("\\", "/", $item);
		   $path = explode("/", $item);
		   $img = array_pop($path);

		   foreach ($ignoreFoldersNoSlash as $pathMin) {
			   $pathNew = $path;
			   $pathNew[] = $pathMin;
			   $pathNew[] = $img;
			   unlink(ROOT . implode("/", $pathNew));
		   }
		   unlink(ROOT . $item);
	   }
	}

    /**
     * @return true
     */
    public function deleteListCategoriesAction()
    {
        $list = \g::request()->post()->get('list');
        $list = json_decode(stripslashes(html_entity_decode($list)));

        if (count($list) < 1) {
            \g::response()->data()->set(['res' => false, 'message' => 'Элементы для удаления не выбраны']);
            return true;
        }

        foreach ($list as $item) {
            $this->deleteCategoriesAction($item);
        }
        \g::response()->data()->set(['res' => true, 'message' => 'Удаление прошло успешно']);
        return true;
    }

    /**
     * @return array
     */
    public function getCategoriesTreeAction()
    {

        function build($elements, $parentId = 0)
        {
            $branch = array();
            foreach ($elements as $element) {

                if ($element['parent_id'] == $parentId) {
                    $parent = build($elements, $element['id']);
                    if ($parent) {
                        $element['parent'] = $parent;
                    }
                    $branch[] = $element;
                }
            }

            return $branch;
        }

        return build($this->getAllCategoriesAction(), 0);
    }

    /**
     * @param $post
     * @return bool
     */
    public function updateCategoriesAction($post = -1)
    {
        $post_json = false;
        if ($post == -1) {
            $post = \g::request()->post()->get();
            $post_json = true;
        }

        $fieldCheck = ['url', 'name', 'title', 'name_abbr', 'keywords', 'description'];
        foreach ($fieldCheck as $item) {
            if (empty($post[$item])) {
                \g::response()->data()->set(['res' => false, 'message' => "Заполните все поля"]);
                return false;
            }
        }
        $Categories = $this->getCategoriesForIdAction($post['id']);
        if (!$Categories) {

            if ($post) {
                \g::response()->data()->set(['res' => false, 'success' => true, 'message' => 'Ошибка добавления']);
                return true;
            }
            return false;
        }

        if ($Categories['active'] == 1 && $post['active'] == 0) {
            $this->disableCategory($post['id']);
        }

        $exception = ['id', 'time_creation', 'last_modified'];
        $test = [];
        foreach ($Categories as $key => &$item) {
            if (in_array($key, $exception)) {
                unset($Categories[$key]);
                continue;
            }
            if (array_key_exists($key, $post) && $post[$key] != $item) {
                $item = $post[$key];
            }
        }

        $Categories['last_modified'] = time();
        $query = \g::db()->query("
            UPDATE `catalog_categories`
            SET `keywords` = ?s,`description` = ?s,`images` = ?s,`nav_parent_url` = ?s,`sort` = ?i,`url` = ?s,`content` = ?s, `name` = ?s,`name_abbr` = ?s, `title` = ?s, `active` = ?i, `last_modified` = ?i, `is_nav` = ?i,`templates` = ?s, `sort_nav` = ?i, `site_map` = ?i
            WHERE `id` = ?i",
            $Categories['keywords'],
            $Categories['description'],
            $Categories['images'],
            $Categories['nav_parent_url'],
            $Categories['sort'],
            $Categories['url'],
            $post['content'],
            $Categories['name'],
            $Categories['name_abbr'],
            $Categories['title'],
            intval($Categories['active']),
            intval($Categories['last_modified']),
            intval($Categories['is_nav']),
            $Categories['templates'],
            intval($Categories['sort_nav']),
            intval($Categories['site_map']),
            intval($post['id'])
        );
        $post['parent_id'] = explode(",", $post['parent_id']);
        $del = \g::db()->query("DELETE FROM `catalog_relations` WHERE `cid` = ?s AND `type` = 2", $post['id']);
        foreach ($post['parent_id'] as $elem) {
            $query = \g::db()->query("
                INSERT INTO `catalog_relations`(
                    `cid`, `pid`, `type`
                ) VALUES (
                    ?i,
                    ?i,
                    ?i
                );", $post['id'], $elem, 2);
        }


        \g::base()->generateSiteMap();
        if ($post_json) {
            \g::response()->data()->set(['res' => false, 'success' => true, 'message' => "Изменения успешно сохранены"]);
            return true;
        }
        return true;

    }

    /**
     * @param $post
     * @return bool
     */
    public function updateProductAction($post = -1)
    {

        $post_json = false;
        if ($post == -1) {
            $post = \g::request()->post()->get();
            $post_json = true;
        }
        $fieldCheck = ['url', 'name', 'title', 'name_abbr', 'keywords', 'description'];
        foreach ($fieldCheck as $item) {
            if (empty($post[$item])) {
                \g::response()->data()->set(['res' => false, 'message' => "Заполните все поля"]);
                return false;
            }
        }

        $sku_list = (array)json_decode(html_entity_decode(stripslashes(\g::request()->post()->get("sku_list"))), true);

        $sku_all_list = $sku_list;

        $sku_all_list[] = $post['sku'];

        $info = \g::db()->getAll("
                SELECT COUNT(*) as 'count' FROM `catalog_products` WHERE url = ?s AND id <> ?s 
                UNION ALL
                SELECT COUNT(*) as 'count' FROM `catalog_products` WHERE sku in (?a) AND id <> ?s 
                UNION ALL
                SELECT COUNT(*) as 'count' FROM `catalog_sku_list` WHERE sku in (?a) AND parent_id <> ?s                 
                ",
            $post['url'], $post['id'],
            $sku_all_list, $post['id'],
            $sku_all_list, $post['id']);
        if ($info[0]['count'] > 0) {
            \g::response()->data()->set(['res' => false, 'message' => "URL должен быть уникальным"]);
            return false;
        }
        if ($this->getOriginalSku()) {
            if ($info[1]['count'] > 0) {
                if (strlen($post['sku']) > 0) {
                    \g::response()->data()->set(['res' => false, 'message' => "Артикуль должен быть уникальным"]);
                    return false;
                }
            }

            if ($info[2]['count'] > 0) {
                if (strlen($post['sku']) > 0) {
                    \g::response()->data()->set(['res' => false, 'message' => "Артикуль должен быть уникальным"]);
                    return false;
                }
            }
        }

        $filters = json_decode(html_entity_decode(stripslashes(\g::request()->post()->get("filters"))), true);

        $product = $this->getProductForIdAction($post['id']);
        if (!$product) {

            if ($post) {
                \g::response()->data()->set(['res' => false, 'success' => true, 'message' => 'Ошибка добавления']);
                return true;
            }
            return false;
        }
        $exception = ['id', 'time_creation', 'last_modified'];
        $change = false;
        foreach ($product as $key => &$item) {
            if (in_array($key, $exception)) {
                unset($product[$key]);
                continue;
            }
            if (!empty($post[$key]) && $post[$key] != $item) {
                $change = true;
                $item = $post[$key];
            }
        }

        $post['parent_id'] = explode(",", $post['parent_id']);
        $product['last_modified'] = time();
        $query = \g::db()->query("
            UPDATE `catalog_products`
            SET  `stock` = ?s,   `keywords` = ?s,`description` = ?s, `sort_nav` = ?s,`is_nav` = ?s,`nav_parent_url` = ?s,`url` = ?s, `templates` = ?s,`sort` = ?i, `name` = ?s,`name_abbr` = ?s, `title` = ?s, `content` = ?s, `images` = ?s, `active` = ?i, `price`  = ?i, `price_old` = ?i, `sku` = ?s, `double_sku` = ?s, `last_modified` = ?i, `site_map` = ?i
            WHERE `catalog_products`.`id` = ?i",
            $post['stock'],
            $post['keywords'],
            $post['description'],
            $post['sort_nav'],
            $post['is_nav'],
            $post['nav_parent_url'],
            $post['url'],
            $post['templates'],
            $post['sort'],
            $post['name'],
            $post['name_abbr'],
            $post['title'],
            $post['content'],
            $post['images'],
            intval($post['active']),
            floatval($post['price']),
            floatval($post['price_old']),
            $post['sku'],
            $post['double_sku'],
            intval($post['last_modified']),
            intval($post['site_map']),
            intval($post['id'])
        );
        $del = \g::db()->query("DELETE FROM `catalog_relations` WHERE `cid` = ?s AND `type` = 1", $post['id']);
        foreach ($post['parent_id'] as $elem) {
            $query = \g::db()->query("
                INSERT INTO `catalog_relations`(
                    `cid`, `pid`, `type`
                ) VALUES (
                    ?i,
                    ?i,
                    ?i
                );", $post['id'], $elem, 1);
        }

        $del = \g::db()->query("DELETE FROM `catalog_filters` WHERE `parent_id` = ?i ", $post['id']);
        $del = \g::db()->query("DELETE FROM `catalog_sku_list` WHERE `parent_id` = ?i ", $post['id']);

        foreach ($filters as $filter) {
            $query = \g::db()->query("
                INSERT INTO `catalog_filters`(
                    `parent_id`, `name`, `value`, `filter`
                ) VALUES (
                    ?i,
                    ?s,
                    ?s,
                    ?i
                );", $post['id'], $filter['name'], $filter['value'], $filter['filter'] ? 1 : 0);
        }

        foreach ($sku_list as $sku) {
            $query = \g::db()->query("
                INSERT INTO `catalog_sku_list`(
                    `parent_id`, `sku`
                ) VALUES (
                    ?i,
                    ?s
                );", $post['id'], $sku);
        }

        \g::base()->generateSiteMap();
        if ($post_json) {
            \g::response()->data()->set(['res' => true, 'success' => true, 'message' => "Изменения успешно сохранены"]);
            return true;
        }
        return true;

    }

    /**
     * @return true
     */
    public function changeAllPricesAction()
    {
        $percent = \g::request()->post()->get('percent');
        if (is_numeric($percent)) {
            $sqlPrice = '`price` = TRUNCATE(`price` + (`price` * ?i / 100.0), 0)';
            $sqlPriceOld = '`price_old` = TRUNCATE(`price_old` + (`price_old` * ?i / 100.0), 0)';
            if (\g::request()->post()->get('kop')) {
                $sqlPrice = '`price` = TRUNCATE(`price` + (`price` * ?i / 100.0), 1)';
                $sqlPriceOld = '`price_old` = TRUNCATE(`price_old` + (`price_old` * ?i / 100.0), 1)';
            }
            \g::db()->query("UPDATE `catalog_products` SET $sqlPrice WHERE `price` != '0.00'", $percent);
            \g::db()->query("UPDATE `catalog_products` SET $sqlPriceOld WHERE `price_old` != '0.00'", $percent);
            \g::response()->data()->set(['res' => true, 'success' => true, 'message' => "Цены успешно изменены"]);
        } else {
            \g::response()->data()->set(['res' => false, 'message' => "Процент должен быть числом!"]);
        }
        return true;
    }

}
