<?php

namespace app\helpers;

use app\models\graphql\Movie;
use app\models\graphql\Person;
use Yii;
use GuzzleHttp;
use yii\helpers\FileHelper;
use yii\helpers\Json;

abstract class GraphModelType {}

class GraphDatabaseAccessLayer
{

    /**
     * @param $graphqlSchema String GraphQL schema filename. Example: Models.graphql
     * @return string
     * @throws \yii\base\Exception
     */
    public static function buildSchemaModels($graphqlSchema) {

        $modelsPath = Yii::getAlias('@app/models/');

        // Get schema data from
        $schema = file_get_contents($modelsPath . $graphqlSchema);

        // Normalize datas and extract types
        $types = array_filter(explode('}', preg_replace(['/\([^)]+\)/'], '', str_replace(['type ', '{', ' ', "\n\n"], '', $schema))));
        for ($i = 0; $i < count($types); $i++) {
            $types[$i] = rtrim($types[$i]);
        }

        // Create path if not exists
        FileHelper::createDirectory($modelsPath . 'graphql', $mode = 0775, $recursive = true);

        // Create model classes
        foreach ($types as $type) {
            self::createModel($type, self::extractTypeNames($types));
        }

        // Return status
        return Json::encode([
            'status' => 'success',
        ]);

    }

    private static function extractTypeNames($types) {
        $allTypeNames = [];

        foreach ($types as $type) {
            $allTypeNames[] = explode("\n", $type)[0];
        }

        return $allTypeNames;
    }

    private static function createModel($type, $allTypeNames) {

        $data = explode("\n", $type);

        // Get className
        $className = $data[0];
        unset($data[0]);

        // Get attributes and generate methods
        $attributes = '';
        $methods = '';
        foreach ($data as $attribute) {
            $parts = explode(':', $attribute);

            // Check if type is standard or GraphModelType
            $isGraphModelType = in_array(self::typeConversion($parts[1], true), $allTypeNames);

            // Set attribute visibility
            if($isGraphModelType) {
                $attrVisibility = "private";
            } else {
                $attrVisibility = "public";
            }

            // Generate attribute
            $attributes .= "\n\n\t/**\n\t* @var " . self::typeConversion($parts[1]) . "\n\t*/\n\t$attrVisibility \$_" . $parts[0] . ";";

            // Generate methods
            if($isGraphModelType) {

                // If is array create empty array otherwise create new empty object
                if(self::isArray($parts[1])) {
                    $newInstance = "[]";
                } else {
                    $newInstance = "new " . self::typeConversion($parts[1], true) . "();";
                }

                $methods .= "\n\n\tpublic function $parts[0]() { return \$this->$parts[0] ?? $newInstance; }";
            }
            // TODO generate methods for all type of attributes
        }

        // Generate template
        $template = "<?php\n\nnamespace app\models\graphql;\n\nuse app\helpers\GraphModelType;\n\nclass $className extends GraphModelType { $attributes\n$methods\n}";

        // Save model
        file_put_contents(Yii::getAlias('@app/models/graphql/' . $className . '.php'), $template);

    }

    private static function typeConversion($type, $ignoreArray = false) {

        $isArray = false;

        // Check if is array
        if(self::isArray($type)) {
            $isArray = true;
            $type = substr($type, 1, strlen($type) - 2);
        }

        // Do conversion
        return str_replace(['Long'], ['int'], $type) . ($isArray && !$ignoreArray ? '[]' : '');

    }

    private static function isArray($type) {
        return substr($type, 0, 1) == '[';
    }

    /**
     * Use this function to update graphql graph in database with provided graph schema
     */
    public static function updateDatabaseGraph() {
        // TODO
    }

    /**
     * @param $gql String GraphQL query
     * @return GraphModelType[]
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function query($gql) {
        $client = new GuzzleHttp\Client();
        $res = $client->request('POST', Yii::$app->params['api_endpoint'], [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(Yii::$app->params['db_username'] . ':' . Yii::$app->params['db_password']),
            ],
            GuzzleHttp\RequestOptions::JSON => [
                'operationName' => null,
                'variables' => [],
                'query' => $gql,
            ],
        ]);
        return self::json2Object(Json::decode($res->getBody()->getContents())['data']);
    }

    private static function json2Object($json) {

        $result = [];

        foreach ($json as $modelName => $instances) {

            // Check if is single object or an array of objects
            if(array_values($instances) !== $instances) {
                $result = self::createModelFromJson($modelName, $instances);
            } else {
                foreach ($instances as $instance) {
                    $result[$modelName][] = self::createModelFromJson($modelName, $instance);
                }
            }

        }

        return $result;

    }

    private static function createModelFromJson($modelName, $params) {
        $modelPath = "app\models\graphql\\$modelName";
        $model = new $modelPath();

        foreach ($params as $paramName => $paramValue) {

            if (method_exists($model, $paramName)/* && is_a($model->$paramName(), 'app\helpers\GraphModelType')*/) {
                $model->$paramName = self::json2Object([str_replace('app\models\graphql\\', '', get_class($model->$paramName())) => $paramValue]);
            } else {
                $model->$paramName = $paramValue;
            }

        }

        return $model;
    }

    public static function mutation($gql) {
        // TODO
    }

}
