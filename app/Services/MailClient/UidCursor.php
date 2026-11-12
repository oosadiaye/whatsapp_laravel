<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * Where an IMAP folder sync has got to, as three numbers kept per folder:
 *
 *  - `uidvalidity`    the server's id for this numbering of the folder; if it changes, start over.
 *  - `last_uid`       the newest message already pulled. New mail is everything above it.
 *  - `backfill_below` history still to pull is everything below this number (0 = all of it is in).
 *
 * The first pass takes the NEWEST batch, so recent mail appears straight away, and the history
 * is then pulled backwards a batch at a time. Pure functions, so the rules are testable without
 * a mail server.
 */
final class UidCursor
{
    /** @param array<string, mixed> $state */
    public static function needsFullResync(array $state, int $currentUidValidity): bool
    {
        $stored = (int) ($state['uidvalidity'] ?? 0);

        return $stored === 0 || $stored !== $currentUidValidity;
    }

    /**
     * After the first pass (or a renumbered folder): `$uids` are the newest batch just pulled.
     *
     * @param  list<int>  $uids
     * @return array{uidvalidity: int, last_uid: int, backfill_below: int}
     */
    public static function afterNewest(int $uidValidity, array $uids, int $batchSize): array
    {
        return [
            'uidvalidity' => $uidValidity,
            'last_uid' => $uids === [] ? 0 : max($uids),
            'backfill_below' => self::olderLeft($uids, $batchSize),
        ];
    }

    /**
     * After a normal pass: `$newUids` are messages above the old `last_uid`; `$olderUids` is the
     * history batch pulled this pass (empty when none was due).
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $newUids
     * @param  list<int>  $olderUids
     * @return array{uidvalidity: int, last_uid: int, backfill_below: int}
     */
    public static function afterIncremental(array $state, int $uidValidity, array $newUids, array $olderUids, int $batchSize): array
    {
        $lastUid = (int) ($state['last_uid'] ?? 0);
        $below = (int) ($state['backfill_below'] ?? 0);

        // A full batch of new mail means more is waiting above; history waits its turn.
        $moreNewWaiting = count($newUids) >= $batchSize;

        if ($below > 1 && ! $moreNewWaiting) {
            $below = self::olderLeft($olderUids, $batchSize);
        } elseif ($below <= 1) {
            $below = 0;
        }

        return [
            'uidvalidity' => $uidValidity,
            'last_uid' => $newUids === [] ? $lastUid : max($lastUid, max($newUids)),
            'backfill_below' => $below,
        ];
    }

    /** True when either kind of mail is still waiting, so the job should queue another pass. */
    public static function hasMore(array $cursor, int $newCount, int $batchSize): bool
    {
        return $newCount >= $batchSize || (int) ($cursor['backfill_below'] ?? 0) > 1;
    }

    /**
     * @param  list<int>  $batch  a newest-first-or-any-order batch of UIDs just pulled
     */
    private static function olderLeft(array $batch, int $batchSize): int
    {
        // Only an empty batch (or one that reaches UID 1) proves the start of the folder was reached.
        // A SHORT batch proves nothing: a message deleted mid-fetch, or a header the server left out,
        // also makes it short, and stopping there would skip all the older mail for good.
        if ($batch === []) {
            return 0;
        }

        $lowest = min($batch);

        return $lowest > 1 ? $lowest : 0;
    }
}
