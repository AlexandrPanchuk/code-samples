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
                        ->select('doctor_id')->distinct()
                        ->from('dc_doctor_specialization AS ds')
                        ->innerJoin('dc_top AS t', 't.model_id = ds.doctor_id')
                        ->where(['ds.spec_id' => array_column($this->specs, 'spec_id')])
                        ->andWhere(['t.model' => Top::MODEL_DOCTOR])
                        ->andWhere(['t.city_id' => $this->searchModel->city_id])
                        ->andWhere(['<>', 'doctor_id', $this->modelDoctor->doctor_id])
                        ->orderBy(['t.position' => SORT_DESC])
                        ->limit(4)
                        ->column();

                        if (!empty($doctors)) {
                            foreach ($doctors as $id) {
                                $doctorModel = Doctor::findOne($id);

                                $priceDoctor = (new Query())
                                    ->select('max(dc_doctor_clinic.price) as max, min(dc_doctor_clinic.price) as min')
                                    ->from('dc_doctor_clinic')
                                    ->where(['doctor_id' => $id])
                                    ->andWhere('dc_doctor_clinic.price <> 0')
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
