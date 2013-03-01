<?php
namespace Craft;

Craft::requirePackage(CraftPackage::Rebrand);

/**
 * Email message model class
 */
class EmailMessageModel extends BaseModel
{
	/**
	 * @access protected
	 * @return array
	 */
	protected function defineAttributes()
	{
		return array(
			'key'      => AttributeType::String,
			'locale'   => AttributeType::Locale,
			'subject'  => AttributeType::String,
			'body'     => AttributeType::String,
			'htmlBody' => AttributeType::String,
		);
	}
}
