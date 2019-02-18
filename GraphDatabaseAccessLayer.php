<?php

namespace app\helpers;

use Yii;
use GuzzleHttp;
use GraphAware\Neo4j\Client\ClientBuilder;
use yii\helpers\FileHelper;
use yii\helpers\Json;

abstract class GraphModelType {}

class GraphDatabaseAccessLayer
{

    /**
     * Build GraphQL schema models and deploy to database schema provided
     * @param $graphqlSchema String
     * @throws \yii\base\Exception
     */
    public static function buildSchema($graphqlSchema) {
        //self::updateDatabaseGraph($graphqlSchema);
        self::buildSchemaModels($graphqlSchema);
    }

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
        $types = self::extractTypes($schema);

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

    /**
     * Extract types from provided schema
     * @param $schema
     * @return array
     */
    private static function extractTypes($schema) {

        $types = [];

        // Normalize and split data
        $entries = explode('}', $schema);
        for($i = 0; $i < count($entries); $i++) {
            $entries[$i] = ltrim(rtrim($entries[$i]));
        }

        // Second normalization
        foreach ($entries as $entry) {

            // Check if entry is a type
            if(substr($entry, 0, 5) == 'type ') {
                $types[] = preg_replace(['/\([^)]+\)/'], '', str_replace(['type ', '{', ' ', "\n\n", '!'], '', $entry));

                // WARNING: regex is removing required fields
                // TODO this should be implemented in future
            }

        }

        return $types;

        /*$types = array_filter(explode('}', preg_replace(['/\([^)]+\)/'], '', str_replace(['type ', '{', ' ', "\n\n"], '', $schema))));
        for ($i = 0; $i < count($types); $i++) {
            $types[$i] = rtrim($types[$i]);
        }
        return $types;*/
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
            /*if($isGraphModelType) {
                $attrVisibility = 'private';
            } else {
                $attrVisibility = 'public';
            }*/

            $attrVisibility = 'public';

            // Generate attribute
            $attributes .= "\n\n\t/**\n\t* @var " . self::typeConversion($parts[1]) . "\n\t*/\n\t$attrVisibility \$" . $parts[0] . ";";

            // Generate methods
            if($isGraphModelType) {
                $methods .= "\n\n\tpublic function get" . ucfirst($parts[0]) . "Class() { return " . self::typeConversion($parts[1], true) . "::class; }";
            }

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
     * Use this function to update graphql graph in database with provided graphql schema
     * @param $graphqlSchema String
     */
    private static function updateDatabaseGraph($graphqlSchema) {

        // Create a client to perform connection and set schema
        $client = ClientBuilder::create()
            ->addConnection('bolt', 'bolt://' . Yii::$app->params['db_username'] . ':' . Yii::$app->params['db_password'] . '@localhost:7687')
            ->build();

        // Load schema
        $schema = file_get_contents(Yii::getAlias("@app/models/$graphqlSchema"));

        // Deploy schema to database
        $query = "CALL graphql.idl('$schema')";

        $client->run($query);

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
                    $result[] = self::createModelFromJson($modelName, $instance);
                }
            }

        }

        return $result;

    }

    private static function createModelFromJson($modelName, $params) {

        // Create new model from class name
        $modelPath = "app\models\graphql\\$modelName";
        $model = new $modelPath();

        foreach ($params as $paramName => $paramValue) {

            // Method to call, if exists, to get types of attributes
            $getClassMethodName = 'get' . ucfirst($paramName) . 'Class';

            if (method_exists($model, $getClassMethodName)) {
                // Iterate through other graphql object
                $model->$paramName = self::json2Object([str_replace('app\models\graphql\\', '', $model->$getClassMethodName()) => $paramValue]);
            } else {
                // Set attribute value
                $model->$paramName = $paramValue;
            }

        }

        return $model;
    }

    /**
     * @deprecated
     * @param $gql
     */
    public static function mutation($gql) {
        // TODO
    }

}
