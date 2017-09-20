<?php
namespace common\components;

use common\models\{City, Clinic, Service};


class PriceFeedGenerator
{
    protected static $callBack = null;

    public static function generateAll($callBack=null)
    {
        self::$callBack = $callBack;
        foreach (City::getForDropDown() as $city) {
            self::log(sprintf("[%s] Starting feed generation for city %s\n", date('Y-m-d H:i:s'), $city->city_id));
            /** @var City $city */
            self::generate($city);
        }
        self::log(sprintf("[%s] done\n", date('Y-m-d H:i:s')));
    }


    public static function generate($city)
    {
        self::feedServices($city);
        self::feedDiagnostics($city);
    }

    /**
     * Generate feeds clinics services
     * @param $city
     */
    private static function feedServices(int $city) : void
    {
        self::log(sprintf("[%s] services", date('Y-m-d H:i:s')));

        $sql = "{query --> :city_id}";

        foreach (\Yii::$app->db->createCommand($sql, ['city_id' => $city->city_id])->query() as $row) {
            self::prepareFeed($row['clinic_id']);
        }

        self::log("\n");
    }

    private static function feedDiagnostics(int $city) : void
    {
        self::log(sprintf("[%s] diagnostic", date('Y-m-d H:i:s')));

        $sql = "{query --> :city_id}";

        foreach (\Yii::$app->db->createCommand($sql, ['city_id' => $city->city_id])->query() as $row) {
            self::prepareFeed($row['clinic_id']);
        }

        self::log("\n");
    }

    /**
     * Prepare items to feed clinics and write feed one clinic
     * @param int $clinic_id
     */
    public static function prepareFeed(int $clinic_id) : void
    {
        $dataItems[$clinic_id] = Service::getTreeNew($clinic_id);
        $clinicName = Clinic::getName($clinic_id);
        $category = $modelItems = [];
        foreach ($dataItems as $items) {
            foreach ($items as $item) {
                foreach ($item as $k => $data) {
                    $category[$data['spec_id'] ?? $data['diagnostic_group_id']] = [
                        'id'    => $data['spec_id'] ?? $data['diagnostic_group_id'] ?? null,
                        'name'  => $data['spec_name'] ?? $data['diagnostic_group_name'] ?? null
                    ];
                    $modelItems[] = [
                        'id'            => $data['service_id'] ?? $data['diagnostic_id'] ?? null,
                        'name'          => $data['name'] ?? $data['diagnostic_name'] ?? null,
                        'categoryId'    => $data['spec_id'] ?? $data['diagnostic_group_id'] ?? null,
                        'price'         => $data['price'] ?? null,
                    ];
                }
            }
        }
        self::writeFeed($modelItems, $category, $clinicName);
    }

    public static function writeFeed(array $items, array $categories, string $clinic_name) : void
    {
        $dir = \Yii::getAlias('@frontend/web/feeds');
        $file = $dir.DIRECTORY_SEPARATOR.'price_feed_'.\Yii::$app->transliter->translate($clinic_name).'.xml';
        if (file_exists($file))
            unlink($file);
        $fh = fopen($file, 'w');

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
        fwrite($fh, '<price date="'.date('Y-m-d H:i:s').'">'."\n");
        fwrite($fh, '<name>'.$clinic_name.'</name>'."\n");

        if ($categories) {
            fwrite($fh, '<catalog>' . "\n");
            foreach ($categories as $category) {
                fwrite($fh, sprintf(
                    "\t" . '<category id="%s"%s>%s</category>' . "\n",
                    $category['id'], empty($category['parentID']) ? '' : ' parentID="' . $category['parentID'] . '"',
                    $category['name']
                ));
            }
            fwrite($fh, '</catalog>' . "\n");
        }

        if (!empty($items)) {
            fwrite($fh, '<items>' . "\n");
            foreach ($items as $item) {
                fwrite($fh, sprintf("\t" . '<item id="%s">' . "\n", $item['id']));
                fwrite($fh, sprintf("\t\t" . '<name>%s</name>' . "\n", $item['name']));
                fwrite($fh, sprintf("\t\t" . '<categoryId>%s</categoryId>' . "\n", $item['categoryId']));
                fwrite($fh, sprintf("\t\t" . '<priceuah>%s</priceuah>' . "\n", $item['price']));
                fwrite($fh, "\t" . '</item>' . "\n");
            }
            fwrite($fh, '</items>' . "\n");
        }

        fwrite($fh, '</price>'."\n");
        fclose($fh);
    }

    public static function log($message) : void
    {
        if (self::$callBack)
            call_user_func(self::$callBack, $message);
    }
}
