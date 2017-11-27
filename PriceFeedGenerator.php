<?php
namespace common\components;

use Yii;
use XMLWriter;
use common\components\helpers\ArrayHelper;
use common\components\helpers\FileSysHelper;
use common\models\{
    City, Clinic, MedicineCompendium, MedicineProduct, Photo, Service, 
    Specialization, DiagnosticGroup
};

class PriceFeedGenerator
{
    protected static $callBack;
    protected static $specializations;
    protected static $diagnosticGroups;

    public static function generateAll($callBack=null)
    {
        ini_set("memory_limit", "1024M");

        FileSysHelper::clearDir( 
            Yii::getAlias('@frontend/web/feeds'), '#\.xml$#' 
        );

        self::$specializations = ArrayHelper::index( Specialization::find()->all(), function($item) {
            return $item->spec_id;
        });

        self::$diagnosticGroups = ArrayHelper::index( DiagnosticGroup::find()->all(), function($item) {
            return $item->diagnostic_group_id;
        });

        self::generateCatsFiles();
        
        self::$callBack = $callBack;
        foreach ( City::getForDropDown() as $city ) {
            self::log(sprintf("[%s] Starting feed generation for city %s\n", date('Y-m-d H:i:s'), $city->city_id));
            /** @var City $city */
            self::generate($city);
        }
        
        // TODO: generate feeds medicine pharmacy
        self::feedClinicsMedicine();
        self::log(sprintf("[%s] done\n", date('Y-m-d H:i:s')));
    }

    public static function generate($city)
    {
        self::feedServices($city);
        self::feedDiagnostics($city);
    }

    private static function generateCatsFiles()
    {
        $fp = fopen( Yii::getAlias('@frontend/web/feeds/specializations.csv'), 'w');
        foreach ( self::$specializations as $spec ) {
            fputcsv( $fp, [
                    $spec->spec_id,
                    $spec->name,
                    $spec->getPhotoUrl( Photo::PREVIEW_362x362 ),
                ], 
                ';', '"'
            );
        }
        fclose($fp);
        
        $fp = fopen( Yii::getAlias('@frontend/web/feeds/diagnostic-groups.csv'), 'w');
        foreach ( self::$diagnosticGroups as $diagGroup ) {
            fputcsv( $fp, [
                    $diagGroup->diagnostic_group_id,
                    $diagGroup->diagnostic_group_name,
                    $diagGroup->getPhotoUrl( Photo::PREVIEW_362x362 ),
                ], 
                ';', '"'
            );
        }
        fclose($fp);
    }
    
    /**
     * Generate feeds pharmacy
     */
    public static function feedClinicsMedicine() : void
    {
        self::prepareFeed(null, 'medicine');
    }

    /**
     * Generate feeds clinics services
     * @param $city
     */
    private static function feedServices($city) : void
    {
        self::log(sprintf(" [%s] services", date('Y-m-d H:i:s')));

        $sql = "SELECT cs.clinic_id
                FROM dc_clinic_service as cs
                    INNER JOIN dc_service as s on s.service_id = cs.service_id
                    INNER JOIN dc_clinic as c on c.clinic_id = cs.clinic_id
                WHERE c.is_active = 1 AND c.is_deleted = 0 AND c.city_id = :city_id
                GROUP BY c.clinic_id";
        
        foreach ( Yii::$app->db->createCommand($sql, ['city_id' => $city->city_id])->query() as $row ) {
            self::prepareFeed( $row['clinic_id'] );
        }

        self::log("\n");
    }

    private static function feedDiagnostics($city) : void
    {
        self::log(sprintf(" [%s] diagnostic", date('Y-m-d H:i:s')));

        $sql = "SELECT cd.clinic_id
                FROM dc_clinic_diagnostic as cd
                    INNER JOIN dc_diagnostic as d on d.diagnostic_id = cd.diagnostic_id
                    INNER JOIN dc_clinic as c on c.clinic_id = cd.clinic_id
                WHERE c.is_active = 1 AND c.is_deleted = 0 AND c.city_id = :city_id
                GROUP BY c.clinic_id";

        foreach ( Yii::$app->db->createCommand($sql, ['city_id' => $city->city_id])->query() as $row ) {
            self::prepareFeed($row['clinic_id']);
        }

        self::log("\n");
    }

    private  static function processData($pharmacies, $data)
    {
        $fh = NULL;
        $lastPharmacy = NULL;
        $buffer = '';

        foreach ($data as $item) {

            $pharmacyId = &$item['pharmacy_id'];

            if ( $pharmacyId != $lastPharmacy ) {

                if ( $lastPharmacy ) {
                    $xml->endElement(); // items
                    $xml->endElement(); // price
                    $xml->endDocument();
                    $xml->flush();
                }

                $pharmacyName = $pharmacies[$pharmacyId]['name'];

                echo "Start generate feed {$pharmacyName} \n";

                $file = Yii::getAlias('@frontend/web/feeds/feeds_pharmacy/') . DIRECTORY_SEPARATOR .
                        'price_feed_medicine_' . Yii::$app->transliter->translate($pharmacyName) . '_' . $pharmacyId . '.xml';

                if ( file_exists($file) ) { unlink($file); }

                $xml = new XMLWriter();

                $xml->openURI($file);
                //$xml->openMemory();
                $xml->startDocument();
                $xml->setIndent(true);

                $xml->startElement("price");
                $xml->writeAttribute('date', date('Y-m-d H:i:s'));

                    $xml->startElement("name");
                    $xml->writeRaw(htmlspecialchars($pharmacyName));
                    $xml->endElement();

                    $xml->startElement("catalog");
                        $xml->startElement("category");
                        $xml->writeAttribute('id', 1);
                        $xml->writeRaw('Лекарственные препараты');
                        $xml->endElement();
                    $xml->endElement();

                $xml->startElement('items');
            }

                $xml->startElement("item");
                $xml->writeAttribute('id', $item['product_id']);

                    $xml->startElement("name");
                    $xml->writeCData($item['name']);
                    $xml->endElement();

                    $xml->startElement("categoryId");
                    $xml->writeRaw(1);
                    $xml->endElement();

                    $xml->startElement("price");
                    $xml->writeRaw($item['price']);
                    $xml->endElement();

                    $modelProduct = MedicineProduct::findOne($item['product_id']);

                    $xml->startElement("image");
                    $xml->writeRaw(MedicineProduct::getUrlPhoto($modelProduct));
                    $xml->endElement();

                    $xml->startElement("vendor");
                    $xml->writeRaw($item['manuf_name'] ? htmlspecialchars($item['manuf_name']) : '');
                    $xml->endElement();

                    $xml->startElement("description");
                    $xml->writeCData($item['manuf_name'] ? $item['manuf_name'] : '');
                    $xml->endElement();

                    $xml->startElement("param");
                    $xml->writeAttribute('name','форма выпуска');
                    $xml->writeRaw( htmlspecialchars($item['rel_form_name']) );
                    $xml->endElement();

                    $xml->startElement("param");
                    $xml->writeAttribute('name','дозировка');
                    $xml->writeRaw(htmlspecialchars($item['dosage_name']));
                    $xml->endElement();

                    $xml->startElement("param");
                    $xml->writeAttribute('name','действующее вещество');
                    $xml->writeRaw(htmlspecialchars($item['substance_name']));
                    $xml->endElement();

                    $xml->startElement("param");
                    $xml->writeAttribute('name','показания');
                    $xml->writeCData($item['indications']);
                    $xml->endElement();

                $xml->endElement();

            $lastPharmacy = $item['pharmacy_id'];
        }

        if ( $xml ) {
            $xml->endElement(); // items
            $xml->endElement(); // price
            $xml->endDocument();
            $xml->flush();
        }
    }

    /**
     * Prepare items to feed clinics and write feed one clinic
     * @param int $clinic_id
     */
    public static function prepareFeed( int $clinic_id = null, $flag = '' ) : void
    {
        if ($flag == 'medicine') {
            $pharmacyId    = 0;
            $pharmacyLimit = 10;
            
            do {
                $pharmacies = Yii::$app->db->createCommand("
                    SELECT ph.pharmacy_id, ph.name, COUNT(1) cnt
                    FROM medicine_pharmacy ph
                        INNER JOIN medicine_pharmacy_product ph_p
                            ON ph.pharmacy_id = ph_p.pharmacy_id
                        INNER JOIN medicine_product p
                            ON ph_p.product_id = p.product_id
                    WHERE ph.pharmacy_id > :pharmacyId
                    GROUP BY ph.pharmacy_id
                    ORDER BY ph.pharmacy_id ASC
                    LIMIT :limit
                ", [':limit' => $pharmacyLimit, ':pharmacyId' => $pharmacyId]
                )->queryAll();

                echo "\n PH_ID = {$pharmacyId} -------------------------------------- \n";

                if ($pharmacies) {

                    $pharmacyIds = ArrayHelper::getColumn($pharmacies, 'pharmacy_id');

                    $pharmacyId = $pharmacyIds[count($pharmacyIds) - 1];

                    $pharmacies = ArrayHelper::index($pharmacies, 'pharmacy_id');

                    $data = Yii::$app->db->createCommand("
                    SELECT ph_p.pharmacy_id, p.name, p.dosage_id, p.release_form_id, p.product_id, ph_p.price,
                           manuf.name AS manuf_name, 
                           rel_form.name AS rel_form_name,
                           dosage.name AS dosage_name,
                           pi.indications, pi.description,
                           prod_substance.name as substance_name
                           
                    FROM medicine_pharmacy_product ph_p
                         INNER JOIN medicine_product p ON ph_p.product_id = p.product_id
                         
                         LEFT JOIN (
                            SELECT medicine_compendium_id AS manufacturer_id, name  
                            FROM medicine_compendium 
                            WHERE root = " . MedicineCompendium::ROOT_MANUFACTURER . " AND lvl > 0
                         ) AS manuf USING (manufacturer_id) 
                        
                        LEFT JOIN (
                            SELECT medicine_compendium_id AS release_form_id, name  
                            FROM medicine_compendium 
                            WHERE root = " . MedicineCompendium::ROOT_RELEASE_FORM . " AND lvl > 0
                         ) AS rel_form USING (release_form_id) 
                        
                        LEFT JOIN (
                            SELECT medicine_compendium_id AS dosage_id, name  
                            FROM medicine_compendium 
                            WHERE root = " . MedicineCompendium::ROOT_DOSAGE . " AND lvl > 0
                        ) AS dosage USING (dosage_id)  
                         
                        LEFT JOIN (
                            SELECT ps.product_id, GROUP_CONCAT(s.name SEPARATOR ', ') AS name  
                            FROM medicine_substance as s
                                 INNER JOIN medicine_product_substance as ps ON ps.substance_id = s.substance_id
                            GROUP BY ps.product_id
                        ) AS prod_substance ON prod_substance.product_id = p.product_id 
        
                        LEFT JOIN medicine_product_instruction as pi 
                            ON pi.item_id = p.product_id AND pi.is_model = 0
        
                    WHERE ph_p.pharmacy_id IN (" . implode(',', $pharmacyIds) . ")
                    ORDER BY ph_p.pharmacy_id ASC
                ")->queryAll();

                    self::processData($pharmacies, $data);
                }
            } while ($pharmacies);
        }
        else {
            $category   = $modelItems = [];
            $dataItems  = Service::getTreeNew($clinic_id);
            $clinicName = Clinic::getName($clinic_id);

            foreach ( $dataItems as $item ) {
                foreach ($item as $k => $data) {
                    $categoryId = $data['spec_id'] ?? $data['diagnostic_group_id'] ?? NULL;
                    
                    $category[$categoryId] = [
                        'id'   => $categoryId,
                        'name' => $data['spec_name'] ?? $data['diagnostic_group_name'] ?? NULL,
                    ];
                    
                    $modelItems[] = [
                        'id'            => $data['service_id'] ?? $data['diagnostic_id'] ?? NULL,
                        'name'          => $data['name'] ?? $data['diagnostic_name'] ?? NULL,
                        'categoryId'    => $categoryId,
                        'spec_id'       => $data['spec_id'] ?? NULL,
                        'diag_group_id' => $data['diagnostic_group_id'] ?? NULL,
                        'price'         => (int)$data['price'] ?? NULL,
                    ];
                }
            }

            self::writeFeed($modelItems, $category, $clinicName);
        }
    }

    /**
     * Get photo url depending on $item fields
     * 
     * @param $item
     * @return string
     */
    static private function getPhotoUrl( $item )
    {
        return 
            isset( self::$specializations[$item['spec_id']] )
                ? self::$specializations[$item['spec_id']]->getPhotoUrl( Photo::PREVIEW_362x362 )
                : (
                    isset( self::$diagnosticGroups[$item['diag_group_id']] )
                        ? self::$diagnosticGroups[$item['diag_group_id']]->getPhotoUrl( Photo::PREVIEW_362x362 )
                        : ''
                  );
    }
    
    public static function writeFeed( array $items, array $categories, string $clinic_name ) : void
    {
        if ( !empty($items) ) {
            $dir  = Yii::getAlias('@frontend/web/feeds');
            $file = $dir . DIRECTORY_SEPARATOR . 'price_feed_clinic_' . Yii::$app->transliter->translate($clinic_name) . '.xml';
            
            if ( file_exists($file) ) { unlink($file); }
            
            $fh = fopen($file, 'w');

            fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            fwrite($fh, '<price date="' . date('Y-m-d H:i:s') . '">' . "\n");
            fwrite($fh, '<name>' . $clinic_name . '</name>' . "\n");

            if ( $categories ) {
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

            fwrite($fh, '<items>' . "\n");
            
            foreach ( $items as $item ) {
                fwrite($fh, sprintf("\t" . '<item id="%s">' . "\n", $item['id']));
                fwrite($fh, sprintf("\t\t" . '<name>%s</name>' . "\n", $item['name']));
                fwrite($fh, sprintf("\t\t" . '<categoryId>%s</categoryId>' . "\n", $item['categoryId']));
                fwrite($fh, sprintf("\t\t" . '<priceuah>%s</priceuah>' . "\n", $item['price']));
                fwrite($fh, sprintf("\t\t" . "<image>%s</image>\n", self::getPhotoUrl($item) ));
                fwrite($fh, "\t" . '</item>' . "\n");
            }
            
            fwrite($fh, '</items>' . "\n");

            fwrite($fh, '</price>' . "\n");
            fclose($fh);
        }
    }

    public static function log($message) : void
    {
        if (self::$callBack)
            call_user_func(self::$callBack, $message);
    }
}