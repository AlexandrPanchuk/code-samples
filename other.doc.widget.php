<?php

namespace common\widgets;

use common\models\{Clinic, Doctor};
use common\models\search\{SearchModel,SearchProvider};
use Yii;
use yii\base\Widget;
use yii\db\Query;
use yii\helpers\Html;

class OtherDoctors extends Widget
{
    public $searchModel = [];
    public $clinics = [];
    public $spec = [];

    public function run()
    {
        $model['model'] = $this->getDoctors();
        return $this->render('other_doctors', $model);
    }

    public function init()
    {
        if ( empty($this->searchModel) ) {
            throw new \InvalidArgumentException('SearchModel parameter cannot be empty');
        }
    }

    /**
     * @return array get other doctors for clinic
     * @throws \yii\base\InvalidConfigException
     */
    public function getDoctors()
    {
        $searchResult = [];
        $doctors = [];
        $specLinks = [];

        foreach ($this->clinics as $keyClinic => $clinic)
        {
            $metro = [];
            $metroStation = explode(',', $clinic['metro']);
            foreach ($metroStation as $station) {
                $metro[] = trim($station);
            }
            $metroIds = $this->getMetroIds($metro);

            $clinic = Clinic::findOne($clinic['id']);

            $district_id = isset($clinic['district_id']) ? $clinic['district_id'] : null ;
            $parent_district_id = isset($clinic['parent_district_id']) ? $clinic['parent_district_id'] : null;

            if (!empty($this->spec[0]->spec_id)) {
                $models = [
                    'class' => SearchModel::className(),
                    'category_id' => SearchModel::CAT_DOCTOR,
                    'city_id' => $this->searchModel->city_id,
                    'page' => 1,
                    'pageSize' => 13,
                    'subject_id' => $this->spec[0]->spec_id,
                    'subject_model' => SearchModel::MODEL_SPECIALIZATION,
                ];

                if (!empty($metroIds)) {
                    $models = array_merge($models, ['metro_id' => $metroIds]);
                }
                if (!empty($parent_district_id) && empty($metroIds)) {
                    $models = array_merge($models, ['district_parent_id' => $parent_district_id]);
                }
                if (!empty($district_id) && empty($metroIds)) {
                    $models = array_merge($models, ['district_id' => $district_id]);
                }


                $searchModel = \Yii::createObject($models);

                $searchProvider = SearchProvider::getProvider($searchModel);
                $searchProvider->useCache = Yii::$app->request->get('useCache', true);
                $searchResult[] = $searchProvider->getResult();
            }

            if (!empty($searchProvider)) {
                foreach ($searchProvider->getIds() as $id) {
                    if ($id != $this->searchModel->subject_id) {
                        // информация о враче
                        $doctors[$keyClinic][] = Doctor::getDataById($id);

                        $doctorModel = Doctor::findOne($id);
                        $specs = $doctorModel->getSpecs()->all();

                        // специализации для врача
                        if (!empty($specs)) {
                            foreach ($specs as $spec) {
                                $specLinks[] = Html::a(
                                    $spec->profession,
                                    ['doctor/index', 'searchModel' => [
                                        'class' => SearchModel::className(),
                                        'city_id' => $this->searchModel->city_id,
                                        'subject_id' => $spec->spec_id,
                                        'subject_model' => SearchModel::MODEL_SPECIALIZATION
                                    ]],
                                    ['class' => 'static-link']
                                );
                            }
                        }
                    }
                }
            }
        }

        /**
         * Формирование массива врачей соответствующей клиники 
         */
        $otherDoctor = [];
        if (!empty($doctors)) {
            foreach ($doctors as $clinic => $modelDoctor) {
                foreach ($modelDoctor as $k => $model) {
                    $doctor = Doctor::findOne($model['doctor_id']);
                    $otherDoctor[$clinic][$k] = [
                        'name' => $model['full_name'],
                        'spec' => !empty($specLinks) ? join( ', ', array_unique($specLinks)) : null,
                        'review' => !(empty($model['reviews_display'])) ? $model['reviews_display'] : null,
                        'url' => Yii::$app->urlManager->createAbsoluteUrl([
                            'doctor/view',
                            'city_id' => $this->searchModel->city_id,
                            'doctor_id' => $model['doctor_id']
                        ]),
                        'photo_url' => $doctor->getPhotoUrl(),
                    ];
                }
            }
        }

        return $otherDoctor;
    }

    /**
     * Metro ids from page
     * @param array $metro
     * @return array
     */
    public function getMetroIds(array $metro) : array
    {
        $metroIds = [];
        if (!empty($metro)) {
            $metroIds = (new Query())
                ->select('dc_metro_station.metro_station_id')
                ->from('dc_metro_station')
                ->where([
                    'name' => $metro
                ])
                ->createCommand()
                ->queryAll();
        }
        if (!empty($metroIds)) {
            foreach ($metroIds as $id) {
                $ids[] = $id['metro_station_id'];
            }
        }

        return  $ids ?? null;
    }
}
