<?php

namespace app\helpers;

use Yii;
use GuzzleHttp;
use GraphAware\Neo4j\Client\ClientBuilder;
use yii\helpers\FileHelper;
use yii\helpers\Json;

/**
 * Class GraphDatabaseAccessLayer. This is the layer to use to communicate with a graphql neo4j database
 * @package app\helpers
 */
class GraphDatabaseAccessLayer
{

    /**
     * Build GraphQL schema models and deploy to database schema provided
     * @param $graphqlSchema String
     * @throws \yii\base\Exception
     */
    public static function buildSchema($graphqlSchema) {
        self::updateDatabaseGraph($graphqlSchema);
        self::buildSchemaModels($graphqlSchema);
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
     * @param $graphqlSchema String GraphQL schema filename. Example: Models.graphql
     * @return string
     * @throws \yii\base\Exception
     */
    public static function buildSchemaModels($graphqlSchema) {

        $modelsPath = Yii::getAlias('@' . self::getModelsPath(false, true));

        // Get schema data from
        $schema = file_get_contents($modelsPath . $graphqlSchema);

        // Normalize datas and extract types
        $types = self::extractTypes($schema);

        // Create path if not exists
        FileHelper::createDirectory($modelsPath . 'graphql');

        // Create model classes
        foreach ($types as $type) {
            self::createModel($type);
        }

        // Return status
        return Json::encode([
            'status' => 'success',
        ]);

    }

    /**
     * Extract types from provided schema
     * @param $schema String Schema provided from a .graphql file
     * @return array
     */
    private static function extractTypes($schema) {

        $types = [];

        // Normalize and split data
        $entries = preg_split("/}\n|}$/", $schema);
        for($i = 0; $i < count($entries); $i++) {
            $entries[$i] = ltrim(rtrim(str_replace("\n\n", "\n", $entries[$i])));
        }

        // Second normalization
        foreach ($entries as $entry) {

            // Check if entry is a type or an interface
            if(substr($entry, 0, 5) == 'type ' || substr($entry, 0, 10) == 'interface ') {

                // Regex:
                // - content in parenthesis
                $parenthesis = '\([^)]+\)';
                // - decorators
                $decorators = '@.*';
                // - extension and implementation
                $typeExtImpl = '(implements|extends).+';
                // - remaining characters
                $other = 'type |interface |{| ';

                // Formatted data
                $formatted = preg_replace(["/$parenthesis|$decorators|$typeExtImpl|$other/"], '', $entry);
                $formattedParts = explode("\n", $formatted, 2);
                $attributes = [];
                foreach (explode("\n", $formattedParts[1]) as $parameter) {

                    // Build parameter
                    $paramParts = explode(':', $parameter);
                    $attributes[] = [
                        'name' => lcfirst($paramParts[0]),
                        'type' => self::typeConversion(str_replace('!', '', $paramParts[1]), true), // Remove required field: !
                        'isArray' => substr($paramParts[1], 0, 1) == '[',
                        'required' => substr($paramParts[1], -1) == '!',
                        'visibility' => 'protected',
                    ];

                }

                // Sort attributes by required fields
                usort($attributes, function ($a, $b) {
                    return $b['required'] <=> $a['required'];
                });

                // Create new type
                $types[] = [
                    'className' => $formattedParts[0],
                    'attributes' => $attributes,
                ];

            }

        }

        return $types;
    }

    /**
     * Create a single model php file used to interact with database
     * @param $type array Particular structure created from types extraction
     */
    // TODO improve this method
    private static function createModel($type) {

        // Get className
        $className = $type['className'];

        // Generate attributes with documentation
        $attributes = self::generateAttributes($type['attributes']);

        // Generate methods
        $methods = self::generateMethods($type['attributes']);

        // Generate constructor
        $constructor = self::generateConstructor($type['attributes']);

        // Generate getClass method
        $getClass = self::generateGetClassMethod($className);

        // Add to methods the generated overridden query method
        $methods .= self::generateQueryMethod();

        // Generate user query attributes
        $queryAttributes = self::generateQueryAttributes($type['attributes']);

        // Generate template
        $template = "<?php\n\nnamespace " . self::getModelsPath(true, false, false) . ";\n\nuse app\helpers\GraphModelType;\n\nclass $className extends GraphModelType { $attributes\n$methods\n$constructor\n$getClass\n}\n\nclass ${className}QueryAttributes {\n$queryAttributes}";

        // Save model
        file_put_contents(Yii::getAlias('@app/models/graphql/' . $className . '.php'), $template);

    }

    /**
     * Generate attributes with relative documentations
     * @param $inputAttributes
     * @return string
     */
    private static function generateAttributes($inputAttributes) {

        $attributes = '';

        // Attributes generator loop
        foreach ($inputAttributes as $attribute) {

            // Check if attribute is required
            $required = '';
            if($attribute['required']) {
                $required = "\n\t * @required This attribute is required for mutations";
            }

            // Save attribute
            $documentation = "\t/**\n\t * @var ${attribute['type']}" . ($attribute['isArray'] ? '[]' : '') . "$required\n\t */";
            $attributes .= "\n\n$documentation\n\t${attribute['visibility']} $${attribute['name']};";
        }

        return $attributes;
    }

    /**
     * Generate all methods for all attributes
     * @param $inputAttributes
     * @return string
     */
    private static function generateMethods($inputAttributes) {

        $methods = '';

        // Methods generator loop
        foreach ($inputAttributes as $attribute) {

            // Check if type is standard or GraphModelType
            $isGraphModelType = self::isGraphModelType($attribute['type']);

            // Generate getter and setter methods for all attributes
            $methods .= "\n\n\tpublic function ${attribute['name']}() {\n\t\tparent::requestAttribute('${attribute['name']}', '${attribute['type']}');\n\t\treturn \$this->${attribute['name']};\n\t}"; // Getter
            $methods .= "\n\n\tpublic function set" . ucfirst($attribute['name']) . "($${attribute['name']}) {\n\t\t\$this->${attribute['name']} = $${attribute['name']};\n\t}"; // Setter

            // Generate methods for defined types
            if($isGraphModelType) {
                $methods .= "\n\n\tpublic function get" . ucfirst($attribute['name']) . "Class() { return ${attribute['type']}::getClass(); }";
            }
        }

        return $methods;

    }

    private static function generateConstructor($inputAttributes) {

        $params = '';
        $directives = '';
        $paramsCount = 0;

        // Generator loop
        foreach ($inputAttributes as $attribute) {

            // Check if type is standard or GraphModelType
            $isGraphModelType = self::isGraphModelType($attribute['type']);

            // Check if is a standard type
            if(!$isGraphModelType) {

                // Adds parameters and directives to constructor
                $params .= "\$${attribute['name']} = null, ";
                $directives .= "\n\t\t\$this->${attribute['name']} = $${attribute['name']};";

                $paramsCount++;

            }

        }

        // Remove trailing characters from parameters
        if($paramsCount > 0) {
            $params = substr($params, 0, strlen($params) - 2);
        }

        return "\n\tpublic function __construct($params) { $directives\n\t}";
    }

    /**
     * Generate a method used to get class name
     * @param $className String
     * @return string
     */
    private static function generateGetClassMethod($className) {
        return "\n\tpublic static function getClass() { return '$className'; }";
    }

    private static function generateQueryMethod() {
        return "\n\n\tpublic static function query(\$attributes, \$callback) {\n\t\treturn parent::executeQuery(self::getClass(), \$callback);\n\t}";
    }

    /**
     * Generate query attributes to use when user wants specific query constraints
     * @param $attributes array Attributes of particular model
     * @return string Attributes list as string
     */
    private static function generateQueryAttributes($attributes) {

        $queryAttributes = '';

        // Generation loop
        foreach ($attributes as $attribute) {
            $queryAttributes .= "\tconst " . $attribute['name']. " = '" . $attribute['name'] . "';\n";
        }

        // Return attributes
        return $queryAttributes;

    }

    /**
     * Check if provided type is child of GraphModelType: if it's a webmaster defined type
     * @param $type String Class name of type you want to check
     * @return bool
     */
    public static function isGraphModelType($type) {
        return is_subclass_of(self::getModelsPath(true) . $type, 'app\helpers\GraphModelType');
    }

    /**
     * Converts a specific graphql type in a standard language defined type
     * @param $type String GraphQL input type
     * @param bool $ignoreArray Set true if you want to ignore characters like '[' and ']'
     * @return string
     */
    private static function typeConversion($type, $ignoreArray = false) {

        $isArray = false;

        // Check if is array
        if(self::isArray($type)) {
            $isArray = true;
            $type = substr($type, 1, strlen($type) - 2);
        }

        // Do conversion
        return str_replace(['Long', 'ID'], ['int', 'mixed'], $type) . ($isArray && !$ignoreArray ? '[]' : '');

    }

    /**
     * Check if provided type is an array of types or single type
     * @param $type String GraphQL type
     * @return bool
     */
    private static function isArray($type) {
        return substr($type, 0, 1) == '[';
    }

    /**
     * Execute GraphQL query
     * @param $gql String GraphQL query
     * @return GraphModelType[]
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws GraphDatabaseAccessLayerException
     */
    public static function doQuery($gql) {

        // Make an HTTP request to endpoint
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

        // Get response
        $response = Json::decode($res->getBody()->getContents());

        // Check if has failed
        if(isset($response['errors'])) {
            throw new GraphDatabaseAccessLayerException($response['errors'][0]['message']);
        }

        // Result values
        return self::json2Object($response['data']);

    }

    /**
     * Convert GraphQL json response to language objects instances
     * @param $json mixed GraphQL json data
     * @return array|mixed
     */
    private static function json2Object($json) {

        $result = [];

        // Walking through json data
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

    /**
     * Create model from json input data
     * @param $modelName String
     * @param $params array Parameters of specified model
     * @return mixed
     */
    private static function createModelFromJson($modelName, $params) {

        // Create new model from class name
        $modelName = self::getModelsPath(true) . $modelName;
        $model = new $modelName();

        foreach ($params as $paramName => $paramValue) {

            // Method to call, if exists, to get types of attributes
            $getClassMethodName = 'get' . ucfirst($paramName) . 'Class';

            // Define setter method
            $setter = "set$paramName";

            if (method_exists($model, $getClassMethodName)) {
                // Iterate through other graphql object
                $model->$setter(self::json2Object([str_replace(self::getModelsPath(true), '', $model->$getClassMethodName()) => $paramValue]));
            } else {
                // Set attribute value
                $model->$setter($paramValue);
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

    /**
     * Get models path in project
     * @param $isGraphQLModel bool
     * @param bool $forwardSlash
     * @param bool $finalSlash
     * @return bool|string
     */
    public static function getModelsPath($isGraphQLModel, $forwardSlash = false, $finalSlash = true) {

        // Get proper string basing on input parameters
        $absPath = !$forwardSlash ? 'app\models\graphql\\' : 'app/models/graphql/';
        if(!$isGraphQLModel) {
            $absPath = !$forwardSlash ? 'app\models\\' : 'app/models/';
        }

        // Remove final slash if required
        if(!$finalSlash) {
            $absPath = substr($absPath, 0, strlen($absPath) - 1);
        }

        // Return path
        return $absPath;
    }

}
