<?php

namespace app\helpers;


/**
 * Class GraphModelType is the base class for all GraphQL models
 * @package app\helpers
 */
abstract class GraphModelType {

    // Flags
    protected $isQuery = false;
    protected $isMutation = false;

    // Requested attributes for a new query
    public $requestedAttributes = [];

    /**
     * Adds required attribute to request list
     * @param $attributeName String Attribute name requested
     * @param $attributeType String Attribute type requested
     */
    public function requestAttribute($attributeName, $attributeType) {
        // If is a query object add attribute request
        if($this->isQuery) {

            // Check if attribute is a custom type
            if(GraphDatabaseAccessLayer::isGraphModelType($attributeType)) {

                // Assign empty attribute to continue tree analyzing
                if($this->$attributeName == null) {

                    // Create new custom empty model
                    $attributeModelName = GraphDatabaseAccessLayer::getModelsPath(true) . $attributeType;
                    $attributeModel = new $attributeModelName();
                    $attributeModel->isQuery = true;
                    $this->$attributeName = [$attributeModel];

                    // Attach child requested attributes to parent
                    $this->requestedAttributes[$attributeName] = [
                        'type' => $attributeType,
                        'value' => &$attributeModel->requestedAttributes,
                    ];

                }

            } else {

                // Otherwise just add it as simple parameter
                $this->requestedAttributes[$attributeName] = [
                    'type' => $attributeType,
                    'value' => $attributeName,
                ];

            }

        }
    }

    /**
     * Build formatted query ready to be submitted to endpoint
     * @param $className String Name of type to fetch fields from
     * @param $requestedAttributes array Requested attributes array
     * @param $isRoot bool Define if this type is the root of the query. True only for first call of this method
     * @return string Query string
     */
    private static function buildQuery($className, $requestedAttributes, $isRoot = false) {
        $query = '';

        // Build requested attributes
        foreach ($requestedAttributes as $key => $attribute) {
            if(GraphDatabaseAccessLayer::isGraphModelType($attribute['type'])) {
                $query .= self::buildQuery($key, $requestedAttributes[$key]['value']) . ',';
            } else {
                $query .= $key . ',';
            }
        }

        // Encapsulate
        $query = "$className{ $query }";

        // Return extra parenthesis only if is root
        return $isRoot ? "{ $query }" : $query;
    }

    /**
     * @param $className String Name of type to fetch fields from
     * @param $callback mixed Callback function to call to build result data
     * @return mixed Result data
     * @throws GraphDatabaseAccessLayerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected static function executeQuery($className, $callback) {

        // Create new empty entity for provided class and set it as query
        $modelName = GraphDatabaseAccessLayer::getModelsPath(true) . $className;
        $model = new $modelName();
        $model->isQuery = true;

        // First execute callback to discover which fields need to be fetched
        $callback([$model]);

        // Build and execute query
        $query = self::buildQuery($className, $model->requestedAttributes, true);
        $queryResult = GraphDatabaseAccessLayer::doQuery($query);

        // Execute final callback
        return $callback($queryResult);
    }

}