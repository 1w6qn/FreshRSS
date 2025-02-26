<?php

class FreshRSS_EntryDAOSQLite extends FreshRSS_EntryDAO {

	public static function isCompressed(): bool {
		return false;
	}

	public static function hasNativeHex(): bool {
		return false;
	}

	protected static function sqlConcat(string $s1, string $s2): string {
		return $s1 . '||' . $s2;
	}

	public static function sqlHexDecode(string $x): string {
		return $x;
	}

	public static function sqlIgnoreConflict(string $sql): string {
		return str_replace('INSERT INTO ', 'INSERT OR IGNORE INTO ', $sql);
	}

	/** @param array<string> $errorInfo */
	protected function autoUpdateDb(array $errorInfo): bool {
		if ($tableInfo = $this->pdo->query("PRAGMA table_info('entry')")) {
			$columns = $tableInfo->fetchAll(PDO::FETCH_COLUMN, 1);
			foreach (['attributes'] as $column) {
				if (!in_array($column, $columns)) {
					return $this->addColumn($column);
				}
			}
		}
		if ($tableInfo = $this->pdo->query("SELECT sql FROM sqlite_master where name='tag'")) {
			$showCreate = $tableInfo->fetchColumn();
			if (stripos($showCreate, 'tag') === false) {
				$tagDAO = FreshRSS_Factory::createTagDao();
				return $tagDAO->createTagTable();	//v1.12.0
			}
		}
		if ($tableInfo = $this->pdo->query("SELECT sql FROM sqlite_master where name='entrytmp'")) {
			$showCreate = $tableInfo->fetchColumn();
			if (stripos($showCreate, 'entrytmp') === false) {
				return $this->createEntryTempTable();	//v1.7.0
			}
		}
		return false;
	}

	public function commitNewEntries(): bool {
		$sql = '
DROP TABLE IF EXISTS `tmp`;
CREATE TEMP TABLE `tmp` AS
	SELECT id, guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
	FROM `_entrytmp`
	ORDER BY date, id;
INSERT OR IGNORE INTO `_entry`
	(id, guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes)
	SELECT rowid + (SELECT MAX(id) - COUNT(*) FROM `tmp`) AS id,
	guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
	FROM `tmp`
	ORDER BY date, id;
DELETE FROM `_entrytmp` WHERE id <= (SELECT MAX(id) FROM `tmp`);
DROP TABLE IF EXISTS `tmp`;
';
		$hadTransaction = $this->pdo->inTransaction();
		if (!$hadTransaction) {
			$this->pdo->beginTransaction();
		}
		$result = $this->pdo->exec($sql) !== false;
		if (!$result) {
			Minz_Log::error('SQL error commitNewEntries: ' . json_encode($this->pdo->errorInfo()));
		}
		if (!$hadTransaction) {
			$this->pdo->commit();
		}
		return $result;
	}

	protected function updateCacheUnreads($catId = false, $feedId = false) {
		$sql = 'UPDATE `_feed` '
		 . 'SET `cache_nbUnreads`=('
		 .	'SELECT COUNT(*) AS nbUnreads FROM `_entry` e '
		 .	'WHERE e.id_feed=`_feed`.id AND e.is_read=0)';
		$hasWhere = false;
		$values = array();
		if ($feedId !== false) {
			$sql .= ' WHERE';
			$hasWhere = true;
			$sql .= ' id=?';
			$values[] = $feedId;
		}
		if ($catId !== false) {
			$sql .= $hasWhere ? ' AND' : ' WHERE';
			$hasWhere = true;
			$sql .= ' category=?';
			$values[] = $catId;
		}
		$stm = $this->pdo->prepare($sql);
		if ($stm && $stm->execute($values)) {
			return true;
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error updateCacheUnreads: ' . $info[2]);
			return false;
		}
	}

	/**
	 * Toggle the read marker on one or more article.
	 * Then the cache is updated.
	 *
	 * @todo change the way the query is build because it seems there is
	 * unnecessary code in here. For instance, the part with the str_repeat.
	 * @todo remove code duplication. It seems the code is basically the
	 * same if it is an array or not.
	 *
	 * @param string|array<string> $ids
	 * @param boolean $is_read
	 * @return integer|false affected rows
	 */
	public function markRead($ids, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if (is_array($ids)) {	//Many IDs at once (used by API)
			//if (true) {	//Speed heuristics	//TODO: Not implemented yet for SQLite (so always call IDs one by one)
				$affected = 0;
				foreach ($ids as $id) {
					$affected += $this->markRead($id, $is_read);
				}
				return $affected;
			//}
		} else {
			$this->pdo->beginTransaction();
			$sql = 'UPDATE `_entry` SET is_read=? WHERE id=? AND is_read=?';
			$values = array($is_read ? 1 : 0, $ids, $is_read ? 0 : 1);
			$stm = $this->pdo->prepare($sql);
			if (!($stm && $stm->execute($values))) {
				$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
				Minz_Log::error('SQL error markRead 1: ' . $info[2]);
				$this->pdo->rollBack();
				return false;
			}
			$affected = $stm->rowCount();
			if ($affected > 0) {
				$sql = 'UPDATE `_feed` SET `cache_nbUnreads`=`cache_nbUnreads`' . ($is_read ? '-' : '+') . '1 '
				 . 'WHERE id=(SELECT e.id_feed FROM `_entry` e WHERE e.id=?)';
				$values = array($ids);
				$stm = $this->pdo->prepare($sql);
				if (!($stm && $stm->execute($values))) {
					$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
					Minz_Log::error('SQL error markRead 2: ' . $info[2]);
					$this->pdo->rollBack();
					return false;
				}
			}
			$this->pdo->commit();
			return $affected;
		}
	}

	/**
	 * Mark all entries as read depending on parameters.
	 * If $onlyFavorites is true, it is used when the user mark as read in
	 * the favorite pseudo-category.
	 * If $priorityMin is greater than 0, it is used when the user mark as
	 * read in the main feed pseudo-category.
	 * Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @todo refactor this method along with markReadCat and markReadFeed
	 * since they are all doing the same thing. I think we need to build a
	 * tool to generate the query instead of having queries all over the
	 * place. It will be reused also for the filtering making every thing
	 * separated.
	 *
	 * @param string $idMax fail safe article ID
	 * @param boolean $onlyFavorites
	 * @param integer $priorityMin
	 * @return integer|false affected rows
	 */
	public function markReadEntries(string $idMax = '0', bool $onlyFavorites = false, int $priorityMin = 0,
		?FreshRSS_BooleanSearch $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadEntries(0) is deprecated!');
		}

		$sql = 'UPDATE `_entry` SET is_read = ? WHERE is_read <> ? AND id <= ?';
		if ($onlyFavorites) {
			$sql .= ' AND is_favorite=1';
		} elseif ($priorityMin >= 0) {
			$sql .= ' AND id_feed IN (SELECT f.id FROM `_feed` f WHERE f.priority > ' . intval($priorityMin) . ')';
		}
		$values = array($is_read ? 1 : 0, $is_read ? 1 : 0, $idMax);

		list($searchValues, $search) = $this->sqlListEntriesWhere('', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadEntries: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Mark all the articles in a category as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param integer $id category ID
	 * @param string $idMax fail safe article ID
	 * @return integer|false affected rows
	 */
	public function markReadCat(int $id, string $idMax = '0', ?FreshRSS_BooleanSearch $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadCat(0) is deprecated!');
		}

		$sql = 'UPDATE `_entry` '
			 . 'SET is_read = ? '
			 . 'WHERE is_read <> ? AND id <= ? AND '
			 . 'id_feed IN (SELECT f.id FROM `_feed` f WHERE f.category=?)';
		$values = array($is_read ? 1 : 0, $is_read ? 1 : 0, $idMax, $id);

		list($searchValues, $search) = $this->sqlListEntriesWhere('', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadCat: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads($id, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Mark all the articles in a tag as read.
	 * @param integer $id tag ID, or empty for targeting any tag
	 * @param string $idMax max article ID
	 * @return integer|false affected rows
	 */
	public function markReadTag($id = 0, string $idMax = '0', ?FreshRSS_BooleanSearch $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == 0) {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadTag(0) is deprecated!');
		}

		$sql = 'UPDATE `_entry` '
			 . 'SET is_read = ? '
			 . 'WHERE is_read <> ? AND id <= ? AND '
			 . 'id IN (SELECT et.id_entry FROM `_entrytag` et '
			 . ($id == 0 ? '' : 'WHERE et.id_tag = ?')
			 . ')';
		$values = array($is_read ? 1 : 0, $is_read ? 1 : 0, $idMax);
		if ($id != 0) {
			$values[] = $id;
		}

		list($searchValues, $search) = $this->sqlListEntriesWhere('e.', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadTag: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
			return false;
		}
		return $affected;
	}
}
