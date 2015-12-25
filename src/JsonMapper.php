<?
namespace lemax10\JsonApiTransformer;

interface JsonMapper {
    /**
     * Returns a string representing the resource name
     * as it will be shown after the mapping.
     *
     * @return string
     */
    public function getAlias();

    /**
     * Returns an array of properties that will be renamed.
     * Key is current property from the class.
     * Value is the property's alias name.
     *
     * @return array
     */
    public function getAliasedProperties();

    /**
     * List of properties in the class that will be  ignored by the mapping.
     *
     * @return array
     */
    public function getHideProperties();

    /**
     * Returns an array of properties that are used as an ID value.
     *
     * @return array
     */
    public function getIdProperties();

    /**
     * Returns a list of URLs. This urls must have placeholders
     * to be replaced with the getIdProperties() values.
     *
     * @return array
     */
    public function getUrls();


    /**
     * Returns an array containing the relationship mappings as an array.
     * Key for each relationship defined must match a property of the mapped class.
     *
     * @return array
     */
    public function getRelationships();

    /**
     * @return array
     */
    public function getMeta();
}