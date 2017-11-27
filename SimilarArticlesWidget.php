<?php
/**
 * Created by PhpStorm.
 * User: alexandr
 * Date: 04.08.17
 * Time: 10:54
 */

namespace common\widgets;


use common\models\BlogItem;
use common\models\BlogItemSpecialization;
use yii\base\Widget;
use yii\db\Expression;

class SimilarArticlesWidget extends Widget
{
    public $searchModel = null;
    public $categories  = null;
    public $specializations = null;

    public function run()
    {
        $items['items'] = $this->getArticle();
        return $this->render('similar_articles', $items);
    }


    public function init()
    {
        if ( empty($this->searchModel) ) {
            throw new \InvalidArgumentException('SearchModel parameter cannot be empty');
        }
    }

    public function getArticle()
    {
        $query = BlogItem::find()
            ->where(['>', 'is_visible', '0'])
            ->andWhere(['<=', 'date_published', new Expression('NOW()')]);

        if (!empty($this->specializations)) {
            foreach ($this->specializations as $specialization) {
                $specs[] = $specialization->spec_id;
            }
        }
        if (!empty($this->categories)) {
            foreach ($this->categories as $category) {
                if (!empty($specs)) {
                    $subQuery = BlogItemSpecialization::find()
                        ->select('item_id')->where(['spec_id' => $specs]);
                    $query->andWhere(['IN', BlogItem::tableName() . '.item_id', $subQuery]);
                }
                $query->andWhere(['<>', BlogItem::tableName().'.item_id', $this->searchModel->subject_id]);
                $query->limit(5);
                $query->joinWith('categories c')
                    ->andWhere(['c.category_id' => $category->category_id]);
            }
        }

        $items = $query
            ->orderBy(['weight' => SORT_DESC, 'date_published' => SORT_DESC])
            ->all();
        return $items;
    }

}
