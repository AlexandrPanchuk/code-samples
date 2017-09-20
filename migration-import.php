<?php

use yii\db\Migration;

class m170712_101044_import_doctors_ru extends Migration
{

    const RU_DOCTORS = '@console/migrations/data/doctors_ru.sql';
    const RU_DOCTORS_EDUCATIONS = '@console/migrations/data/doctor_educations.sql';
    const RU_DOCTOR_CLINIC = '@console/migrations/data/clinic_doctors.sql';
    const DOCTORS_PRICE = '@console/migrations/data/doctors_price.sql';

    const DOCTOR_PHOTO_PATH = '@console/migrations/data/photo/doctor_resize/';

    public function init()
    {
        Yii::$app->transliter->replaceHyphen = false;
        parent::init();
    }

    public function up()
    {
        if ( Yii::$app->site->countryIso2 == 'ru' ) {
            $this->importRuDoctors();
            $this->importEducationDoctors();
            $this->importSpecialization();
            $this->relatedSpecializationDoctor();
            $this->importDegreeDoctors();
            $this->importPriceDoctors();
            $this->addPhotoDoctor();
        }
    }

    /**
     * Insert ru doctors
     */
    public function importRuDoctors()
    {
        /** Import doctors dump **/
        if (file_exists(Yii::getAlias(self::RU_DOCTORS))) {
            exec('mysql --host=' . $this->getDsnAttribute('host', $this->db->dsn)
                . ' --user=' . $this->db->username
                . ' --password=' . $this->db->password
                . ' ' . $this->getDsnAttribute('dbname', $this->db->dsn)
                . ' < ' . Yii::getAlias(self::RU_DOCTORS));
        }

        $data_doctors_ru = $this->db->createCommand("SELECT d_id, doctor_original_id , d_name FROM doc ")->queryAll();

        /** Preparation of doctors */
        $ru_doctors = $this->prepareDoctors($data_doctors_ru);

        $this->addColumn('dc_doctor', 'external_id', 'int unsigned default null');

        foreach ($ru_doctors as $k => $doctor) {
            $exists = (bool)$this->db->createCommand("SELECT alias FROM {{%doctor}} WHERE alias = :alias LIMIT 1" ,
                ['alias' => $doctor['alias']])->queryOne();
            if ($exists) {
                $id = $k+1;
                $doctor['alias'] .= '_'.$id;
            }
            $this->insert('dc_doctor', array(
                'alias' => $doctor['alias'],
                'full_name' => $doctor['doctor_name'],
                'external_id' => $doctor['external_id']
            ));
        }
    }

    /**
     * Preparation of doctors
     */
    private function prepareDoctors(array $ru_doctors)
    {
        $doctors = [];
        foreach ($ru_doctors as $doctor) {
            $doctors[] = [
                'alias'       => Yii::$app->transliter->translate(trim($doctor['doctor_name'])),
                'doctor_name' => trim($doctor['doctor_name']),
                'external_id' => $doctor['doctor_original_id']
            ];
        }

        return isset($doctors) ? $doctors : null;

    }

    /**
     * Insert education to doctors
     */
    public function importEducationDoctors()
    {
        /** Import doctor_education dump */
        if (file_exists(Yii::getAlias(self::RU_DOCTORS_EDUCATIONS))) {
            exec('mysql --host=' . $this->getDsnAttribute('host', $this->db->dsn)
                . ' --user=' . $this->db->username
                . ' --password=' . $this->db->password
                . ' ' . $this->getDsnAttribute('dbname', $this->db->dsn)
                . ' < ' . Yii::getAlias(self::RU_DOCTORS_EDUCATIONS));
        }

        $this->createIndex('doctor_original-idx', '{{%doctor}}', 'external_id');

        $doctors_ru = $this->db->createCommand("SELECT external_id FROM dc_doctor")->queryAll();
        foreach ($doctors_ru as $doctor) {
            $educations_doctor = $this->db->createCommand("
                SELECT CONCAT('<li>',education_name, ' (',education_level,')', '</li>')
                FROM educations WHERE education_doctor_original_id = :original_id ", ['original_id' => $doctor['external_id']])
                ->queryColumn();

            $list = !empty($educations_doctor) ? '<p>Образование</p><ul>' . implode('', $educations_doctor) . '</ul>' : '';

            if (!empty($list)) {
                $this->update(
                    'dc_doctor',
                    ['full_desc' => $list],
                    ['external_id' => $doctor['external_id']]
                );
            }
        }
    }

    /**
     * Import specializations doctors
     */
    public function importSpecialization()
    {
        $specializations = $this->db->createCommand("SELECT doctor_specialisation FROM doctors")->queryAll();
        foreach ($specializations as $k => $specialization ) {
            $spec[] = explode(',', $specialization['doctor_specialisation']);
        }

        $specs = [];
        array_walk_recursive($spec, function($value, $key) use (&$specs){
            $specs[] = trim($value);
        });
        $specs = array_unique($specs);

        foreach ($specs as $specializationDoctor) {
            $this->insert('dc_specialization', array(
                'name'  => $specializationDoctor,
                'alias' => Yii::$app->transliter->translate($specializationDoctor),
            ));
        }
    }

    /**
     * link doctor and specialization
     */
    public function relatedSpecializationDoctor()
    {
        $this->execute("
          INSERT INTO dds (d_id, spec_id, for_links)
          SELECT dd.doc_id, spec_id, 0 AS for_links
          FROM doc
          INNER JOIN ds
          ON LOCATE(name, ds) > 0
          INNER JOIN dd
          ON doctor_original_id = dc_doctor.external_id");
    }

    /**
     * Import degree, characteristics, experience
     */
    public function importDegreeDoctors()
    {
        $mapDagree = [
            'степень неизвестна'    => 0,
            'без степени'           => 0,
            'кандидат наук'         => 1,
            'доктор наук'           => 2
        ];
        
        $mapCharacteristics = [
            'категория неизвестна'  => 0,
            '1 категория'           => 2,
            '2 категория'           => 1,
            'высшая категория'      => 3
        ];

        $degree = $this->db->createCommand("
            SELECT doctor_original_id, doctor_degree, doctor_category, doctor_experience FROM doctors
        ")->queryAll();

        foreach ($degree as $additionalInfo) {
            $this->update('dc_doctor', array(
                'gender' =>
                    array_key_exists($additionalInfo['doctor_degree'], $mapDagree)
                        ? $mapDagree[$additionalInfo['doctor_degree']]
                        : 0,
                'characteristics' =>
                    array_key_exists($additionalInfo['doctor_category'], $mapCharacteristics)
                        ? $mapCharacteristics[$additionalInfo['doctor_category']]
                        : 0,
                'experience' =>
                    preg_match('/[0-9]+/', $additionalInfo['doctor_experience'], $match)
                        ? $match[0]
                        : 0,
            ), ['external_id' => $additionalInfo['doctor_original_id']]);
        }
    }

    /**
     * Add relations doctor -> clinic and update max price doctors
     */
    public function importPriceDoctors()
    {
        /** Import doctors price dump **/
        if (file_exists(Yii::getAlias(self::DOCTORS_PRICE))) {
            exec('mysql --host=' . $this->getDsnAttribute('host', $this->db->dsn)
                . ' --user=' . $this->db->username
                . ' --password=' . $this->db->password
                . ' ' . $this->getDsnAttribute('dbname', $this->db->dsn)
                . ' < ' . Yii::getAlias(self::DOCTORS_PRICE));
        }
        
        // insert dc_doctor_clinics
        $this->execute("INSERT INTO ddc (doc_id, clinic_id, price)
            SELECT doc_id, clinic_id, clinic_doctor_price
            FROM clinic_doctors_price
            INNER JOIN dc_doctor ON clinic_doctors_price.clinic_doctor_original_id = dc_doctor.external_id
            INNER JOIN dc_clinic ON clinic_doctors_price.clinic_doctor_clinic_id = dc_clinic.external_id");
        
        
        // add max price to doctor
        $max_price = $this->db->createCommand("
            SELECT max(dc_doctor_clinic.price) as maxprice, dc_doctor_clinic.doctor_id 
            FROM dc_doctor_clinic 
            GROUP BY doctor_id")->queryAll();
        
        foreach ($max_price as $price) {
            if ($price['maxprice'] != 0.00) {
                $this->update('dc_doctor', array(
                    'doctor_price' => $price['maxprice'],
                ), ['doctor_id' => $price['doctor_id']]);
            }
        }
    }

    /**
     * Add photos to doctor
     */
    public function addPhotoDoctor()
    {
        $photo = new \common\models\Photo();

        if ($handle = opendir(Yii::getAlias(self::DOCTOR_PHOTO_PATH))) {
            $i = 0;
            while (false !== ($file = readdir($handle))) {
                if ($i > 2) {
                    $idDoctor = str_replace('.jpg', '' ,$file);
                    $filename = Yii::getAlias(self::DOCTOR_PHOTO_PATH).$file;
                    $photo->model_type = 1;
                    $photo->model_id = $idDoctor;
                    $photo->is_main = 1;
                    $photo->savePhotoFromFile($filename);
                }
                $i++;
            }
        }
    }


    private function getDsnAttribute($name, $dsn)
    {
        if (preg_match('/' . $name . '=([^;]*)/', $dsn, $match)) {
            return $match[1];
        } else {
            return null;
        }
    }

    public function down()
    {
        if ( Yii::$app->site->countryIso2 == 'ru' ) {
            $this->execute('SET FOREIGN_KEY_CHECKS=0');

            // import doctors
            $this->truncateTable('dd');
            $this->dropColumn('dd', 'ext_id');

            //educations doctors
            $this->update('dd', ['desc' => null]);
            $this->dropIndex('doctor_original-idx', 'dd');

            //specialization
            $this->truncateTable('ds');
            $this->truncateTable('dds');

            //doctor-clinic
            $this->truncateTable('ddc');

            //photo doctor
            $this->truncateTable('dp');

            $this->execute('SET FOREIGN_KEY_CHECKS=1');
        }
    }

}

