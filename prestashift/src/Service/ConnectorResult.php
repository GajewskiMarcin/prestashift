<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * Modifié par : Thierry Laval <contact@thierrylaval.dev>
 * @copyright 2026 Thierry Laval
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
        // The mode used to be ignored, which is a quiet way to hand back
        // something other than what the caller asked for: FETCH_COLUMN then
        // returned whole rows, and casting those to int gave 1 for every
        // record. Code that reads a single column this way is normal, so the
        // modes this module uses are honoured here.
        if ($mode === \PDO::FETCH_COLUMN) {
            $out = [];
            foreach ($this->data as $row) {
                $values = array_values((array) $row);
                $out[] = isset($values[0]) ? $values[0] : null;
            }

            return $out;
        }
        if ($mode === \PDO::FETCH_NUM) {
            $out = [];
            foreach ($this->data as $row) {
                $out[] = array_values((array) $row);
            }

            return $out;
        }

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
