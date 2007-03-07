<?php

/*
+---------------------------------------------------------------------------+
| Max Media Manager v0.3                                                    |
| =================                                                         |
|                                                                           |
| Copyright (c) 2003-2006 m3 Media Services Limited                         |
| For contact details, see: http://www.m3.net/                              |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/

require_once MAX_PATH . '/lib/openads/Dal.php';
require_once 'Date.php';
require_once 'MDB2.php';
require_once 'MDB2/Schema.php';

/**
 * An abstract class defining the interface for creating Openads database tables.
 *
 * Note that only permanent tables are created with the table prefix defined in the
 * configuration .ini file - temporary tables do NOT use the table prefix. This is
 * because temporary tables are not defined in the table array in the configuration
 * .ini file, and as such, must be referenced in the Data Abstraction Layer code
 * directly, and this is easier to do if the prefix doesn't have to be prepended
 * in order to do so.
 *
 * @package    OpenadsDal
 * @subpackage Table
 * @author     Andrew Hill <andrew.hill@openads.org>
 */
class Openads_Table
{

    /**
     * An instance of the Openads_Dal class.
     *
     * @var Openads_Dal
     */
    var $oDbh;

    /**
     * An instance of the MDB2_Schema class.
     *
     * @var MDB2_Schema
     */
    var $oSchema;

    /**
     * An array containing the database definition, as parsed from
     * the XML schema file.
     *
     * @var array
     */
    var $aDefinition;

    /**
     * Should the tables be created as temporary tables?
     *
     * @var boolean
     */
    var $temporary = false;

    /**
     * The class constructor method.
     */
    function Openads_Table()
    {
        $this->oDbh = &$this->_getDbConnection();
    }

    /**
     * A private method to manage creation of the utilised Openads_Dal class.
     *
     * @access private
     * @return mixed Reference to an MDB2 connection resource, or PEAR_Error
     *               on failure to connect.
     */
    function &_getDbConnection()
    {
        return Openads_Dal::singleton();
    }

    /**
     * A method to initialise the class by parsing a database XML schema file, so that
     * the class will be ready to create/drop tables for the supplied schema.
     *
     * @param string $file The name of the database XML schema file to parse for the
     *                     table definitions.
     * @return boolean True if the class was initialised correctly, false otherwise.
     */
    function init($file)
    {
        // Ensure that the schema XML file can be read
        if (!is_readable($file)) {
            MAX::debug('Unable to read the database XML schema file: ' . $file, PEAR_LOG_ERR);
            return false;
        }
        // Create an instance of MDB2_Schema to parse the schema file
        $options = array('force_defaults'=>false);
        $this->oSchema = &MDB2_Schema::factory($this->oDbh, $options);
        // Parse the schema file
        $this->aDefinition = $this->oSchema->parseDatabaseDefinitionFile($file);
        if (PEAR::isError($this->aDefinition)) {
            MAX::debug('Error parsing the database XML schema file: ' . $file, PEAR_LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * A private method to test if the class has been correctly initialised with a
     * valid database XML schema file.
     *
     * @return boolean True if the class has been correctly initialised, false otherwise.
     */
    function _checkInit()
    {
        if (is_null($this->aDefinition)) {
            MAX::debug('No database XML schema file parsed, cannot create table', PEAR_LOG_ERR);
            return false;
        } else if (PEAR::isError($this->aDefinition)) {
            MAX::debug('Previous error parsing the database XML schema file', PEAR_LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * A method for creating a table from the currently parsed database XML schema file.
     *
     * @param string $table The name of the table to create, excluding table prefix.
     * @param Date $oDate An optional date for creating split tables. Will use current
     *                    date if the date is required for creation, but not supplied.
     * @return mixed The name of the table created, or false if the table was not able
     *               to be created.
     */
    function createTable($table, $oDate = NULL)
    {
        $conf = $GLOBALS['_MAX']['CONF'];
        if (!$this->_checkInit()) {
            return false;
        }
        // Does the table exist?
        if (!is_array($this->aDefinition['tables'][$table])) {
            MAX::debug('Cannot find table ' . $table . ' in the XML schema file', PEAR_LOG_ERR);
            return false;
        }
        $tableName = $table;
        // Does a table prefix need to be added to the table name?
        if ($conf['table']['prefix'] && !$this->temporary) {
            $tableName = $conf['table']['prefix'] . $tableName;
        }
        // Are split tables in operation, and is the table designed to be split?
        if (($conf['table']['split']) && ($conf['splitTables'][$table])) {
            if ($oDate == NULL) {
                $oDate = new Date();
            }
            $tableName = $tableName . '_' . $oDate->format('%Y%m%d');
        }
        // Prepare the options array
        $aOptions = array();
        if ($this->temporary) {
            $aOptions['temporary'] = true;
        }
        $aOptions['type'] = $conf['table']['type'];
        // Merge any primary keys into the options array
        if ($this->aDefinition['tables'][$table]['indexes']['primary']) {
            $aOptions['primary'] = $this->aDefinition['tables'][$table]['indexes']['primary']['fields'];
        }
        // Create the table
        MAX::debug('Creating the ' . $tableName . ' table', PEAR_LOG_DEBUG);
        PEAR::pushErrorHandling(null);
        $result = $this->oSchema->createTable($tableName, $this->aDefinition['tables'][$table], false, $aOptions);
        PEAR::popErrorHandling();
        if (PEAR::isError($result)) {
            MAX::debug('Unable to create the table ' . $table, PEAR_LOG_ERR);
            return false;
        }
        return $tableName;
    }

    /**
     * A method for creating all tables from the currently parsed database XML schema file.
     *
     * @param Date $oDate An optional date for creating split tables. Will use current
     *                    date if the date is required for creation, but not supplied.
     * @return boolean True if all tables created successfuly, false otherwise.
     */
    function createAllTables($oDate = NULL)
    {
        if (!$this->_checkInit()) {
            return false;
        }
        foreach ($this->aDefinition['tables'] as $tableName => $aTable) {
            $result = $this->createTable($tableName, $oDate);
            if (!$result) {
                return false;
            }
        }
        return true;
    }

    /**
     * A method to easily drop a table.
     *
     * @param string $table The table name to drop. Must be the complete table name in use,
     *                      as no prefix or data values will be added before dropping the table.
     * @return boolean True if table dropped, false otherwise.
     */
    function dropTable($table)
    {
        MAX::debug('Dropping table ' . $table, PEAR_LOG_DEBUG);
        PEAR::pushErrorHandling(null);
        $result = $this->oDbh->manager->dropTable($table);
        PEAR::popErrorHandling();
        if (PEAR::isError($result)) {
            MAX::debug('Unable to drop table ' . $table, PEAR_LOG_ERROR);
            return false;
        }
        return true;
    }

    /**
     * A method for dropping all tables from the currently parsed database XML schema file.
     * Does not drop any tables that are set up to be "split", if split tables is enabled.
     *
     * @return boolean True if all tables dropped successfuly, false otherwise.
     */
    function dropAllTables()
    {
        $conf = $GLOBALS['_MAX']['CONF'];
        if (!$this->_checkInit()) {
            return false;
        }
        $allTablesDropped = true;
        foreach ($this->aDefinition['tables'] as $tableName => $aTable) {
            if (($conf['table']['split']) && ($conf['splitTables'][$tableName])) {
                // Don't drop
                continue;
            }
            MAX::debug('Dropping the ' . $tableName . ' table', PEAR_LOG_DEBUG);
            $result = $this->dropTable($conf['table']['prefix'].$tableName);
            if (PEAR::isError($result)) {
                MAX::debug('Unable to drop the table ' . $table, PEAR_LOG_ERROR);
                $allTablesDropped = false;
            }
        }
        return $allTablesDropped;
    }

    /**
     * A method for dropping a temporary table.
     *
     * @param string $table The temporary table to drop.
     * @return boolean True if table dropped correctly, false otherwise.
     *
     * @TODO May not be database agnostic. MDB2 Doesn't support dropping temporary tables, yet.
     */
    function dropTempTable($table)
    {
        $oDbh = &Openads_Dal::singleton();
        $query = 'DROP TEMPORARY TABLE ' . $table;
        MAX::debug('Dropping temporary table ' . $table, PEAR_LOG_DEBUG);
        PEAR::pushErrorHandling(null);
        $result = $oDbh->query($query);
        PEAR::popErrorHandling();
        if (PEAR::isError($result)) {
            MAX::debug('Unable to drop temporary table ' . $table, PEAR_LOG_ERROR);
            return false;
        }
        return true;
    }
}

?>
