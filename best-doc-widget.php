<?php

namespace common\widgets;

use common\models\{Doctor, Top};
use common\models\search\SearchModel;
use Yii;
use yii\base\Widget;
use yii\db\Query;

class BestDoctorsWidget extends Widget
{

    public $modelDoctor = [];
    public $clinics     = [];
    public $specs       = [];
    public $searchModel = [];

    public function run()
    {
        $doctors = [];
        if (Yii::$app->site->countryIso2 == 'ua') {
            $doctors['model'] = $this->getDoctorsUa();
        } elseif (Yii::$app->site->countryIso2 == 'ru') {
            $doctors['model'] = $this->getDoctorsRu();
        }

        return $this->render('best_doctors', $doctors);

    }

    public function init()
    {
        if ( empty($this->searchModel) ) {
            throw new \InvalidArgumentException('SearchModel parameter cannot be empty');
        }
    }


    /**
     * The doctor's conclusions in which there is no contract clinics
     * @return array $dataModels
     */
    private function getDoctorsUa()
    {
        $clinics = \frontend\models\Doctor::getCachedClinics([$this->modelDoctor->doctor_id]);
        $dataModels  = [];

        if (!empty($clinics)) {
            $contract = [];
            foreach ($clinics as $key => $clinic) {
                $contract = array_column($clinic, 'contract');
            }

            // If a clinic does not have a contract
            if (array_search(0, $contract) !== false) {
                if (!empty($this->specs)) {

                    $doctors = (new Query())
                        ->select('id')->distinct()
                        ->from('dds AS ds')
                        ->innerJoin('dt AS t', 't.mod_id = d.doc_id')
                        ->where(['d.s_id' => array_column($this->specs, 's_id')])
                        ->andWhere(['t.m' => Top::MODEL_DOCTOR])
                        ->andWhere(['t.c_id' => $this->searchModel->c_id])
                        ->andWhere(['<>', 'doc_id', $this->modelDoctor->d_id])
                        ->orderBy(['t.position' => SORT_DESC])
                        ->limit(4)
                        ->column();

                        if (!empty($doctors)) {
                            foreach ($doctors as $id) {
                                $doctorModel = Doctor::findOne($id);

                                $priceDoctor = (new Query())
                                    ->select('max(ddc.price) as max, min(ddclinic.price) as min')
                                    ->from('dcc')
                                    ->where(['d_id' => $id])
                                    ->andWhere('ddc.price <> 0')
                                    ->all();

                                // If the minimum and maximum prices coincide - remove the minimum
                                if ($priceDoctor[0]['max'] == $priceDoctor[0]['min']) {
                                    unset($priceDoctor[0]['min']);
                                }

                                // specialization doctors
                                $specs = $doctorModel->getSpecs()->all();
                                $dataSpec = $this->getSpecsDoctor($specs);

                                // get array doctors
                                $dataModels[] = [
                                    'name'      => $doctorModel->full_name,
                                    'url'       => Yii::$app->urlManager->createAbsoluteUrl(['doctor/view', 'searchModel' => [
                                        'class'         => SearchModel::className(),
                                        'subject_model' => SearchModel::MODEL_DOCTOR,
                                        'subject_id'    => $doctorModel->doctor_id,
                                        'city_id'       => $this->searchModel->city_id
                                    ]]),
                                    'specs'     => !empty($dataSpec) ? $dataSpec : null,
                                    'rating'    => number_format($doctorModel->rating/10, 1, '.', ''),
                                    'price'     => !empty($priceDoctor) ? $priceDoctor : null,
                                    'photo_url' => $doctorModel->getPhotoUrl(),
                                ];
                            }
                    }
                }
            }
        }

        return $dataModels;

    }

    private function getSpecsDoctor( array $specs) : array 
    {
        $dataSpec = [];
        if (!empty($specs)) {
            foreach ($specs as $spec) {
                $dataSpec[] = [
                    'name' => $spec->profession,
                    'url'  => Yii::$app->urlManager->createAbsoluteUrl(['doctor/index', 'searchModel' => [
                        'class' => SearchModel::className(),
                        'city_id' => $this->searchModel->city_id,
                        'subject_id' => $spec->spec_id,
                        'subject_model' => SearchModel::MODEL_SPECIALIZATION
                    ]]),
                ];
            }
        }
        return $dataSpec;
    }


}
