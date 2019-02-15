<?php

namespace app\helpers;

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

        // Get attributes and generate constructor directives
        $attributes = '';
        $constructorDirectives = [];
        foreach ($data as $attribute) {
            $parts = explode(':', $attribute);

            // Generate attribute
            $attributes .= "\t/**\n\t* @var " . self::typeConversion($parts[1]) . "\n\t*/\n\tpublic $" . $parts[0] . ";\n\n";

            // Generate constructor directive
            if(in_array(self::typeConversion($parts[1], false), $allTypeNames)) {
                $constructorDirectives .= "\n\t\t\$this->$parts[0] = new " . self::typeConversion($parts[1], false) . "();";
            }
        }

        // Generate template
        $template = "<?php\n\nnamespace app\models\graphql;\n\nuse app\helpers\GraphModelType;\n\nclass $className extends GraphModelType {\n\n$attributes\n\n/**\n\t* $className constructor.\n\t*/\n\tpublic function __construct() { $constructorDirectives\n\t}\n}";

        // Save model
        file_put_contents(Yii::getAlias('@app/models/graphql/' . $className . '.php'), $template);

    }

    private static function typeConversion($type, $ignoreArray = true) {

        $isArray = false;

        // Check if is array
        if(substr($type, 0, 1) == '[') {
            $isArray = true;
            $type = substr($type, 1, strlen($type) - 2);
        }

        // Do conversion
        return str_replace(['Long'], ['int'], $type) . ($isArray && !$ignoreArray ? '[]' : '');

    }

    /**
     * Use this function to update graphql graph in database with provided graph schema
     */
    public static function updateDatabaseGraph() {
        // TODO
    }

    /**
     * @param $gql String GraphQL query
     * @return mixed
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
            foreach ($instances as $instance) {

                $modelPath = "app\models\graphql\\$modelName";
                $model = new $modelPath();
                foreach ($instance as $paramName => $paramValue) {

                    if(is_a($model->$paramName, 'app\helpers\GraphModelType')) {
                        $model->$paramName = "TODO";
                    } else {
                        $model->$paramName = $paramValue;
                    }

                }

                $result[$modelName][] = $model;
            }
        }

        return $result;

    }

    public static function mutation($gql) {
        // TODO
    }

}
