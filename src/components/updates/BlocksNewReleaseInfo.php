<?php
namespace Blocks;

/**
 * Stores the info for a Blocks release.
 */
class BlocksNewReleaseInfo
{
	public $version;
	public $build;
	public $date;
	public $notes;
	public $type;
	public $critical;
	public $manualUpdateRequired;

	/**
	 * @param array $properties
	 */
	function __construct($properties = array())
	{
		$this->version = isset($properties['version']) ? $properties['version'] : null;
		$this->build = isset($properties['build']) ? $properties['build'] : null;
		$this->critical = isset($properties['critical']) ? $properties['critical'] : null;
		$this->date = isset($properties['date']) ? $properties['date'] : null;
		$this->notes = isset($properties['notes']) ? $properties['notes'] : null;
		$this->type = isset($properties['type']) ? $properties['type'] : null;
		$this->manualUpdateRequired = isset($properties['manualUpdateRequired']) ? $properties['manualUpdateRequired'] : null;
	}
}
