<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service;

/**
 * Result of a source query (PDOStatement-like), shared by the bridge and the
 * direct database connection. In its own file so the autoloader finds it in
 * direct mode too, where ConnectorClient is never loaded.
 */
class ConnectorResult
{
    private $data;
    private $cursor = 0;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function fetchAll($mode = null)
    {
        return $this->data;
    }

    public function fetch($mode = null)
    {
        if (isset($this->data[$this->cursor])) {
            return $this->data[$this->cursor++];
        }
        return false;
    }

    public function fetchColumn($column_number = 0)
    {
        if (empty($this->data) || !isset($this->data[0])) {
            return false;
        }
        $row = array_values($this->data[0]);
        return $row[$column_number] ?? false;
    }
}
