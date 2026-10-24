<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Timeline entry categories. Mirrors the frontend's config/categories.js.
 *
 * Two tiers, deliberately separate:
 *
 *  - Selectable cases are the categories a user may choose. They are the only
 *    values accepted for new writes.
 *  - Retired cases remain declared purely so Eloquent can still hydrate rows
 *    created before retirement. Deleting a case outright would make the
 *    enum cast in TimelineEntry::casts() throw a ValueError on those rows and
 *    take GET /schedule/today down for every affected account.
 *
 * Retiring a category is therefore a two-step change: move its case under the
 * retired list here, and drop it from CATEGORIES in the frontend config.
 */
enum Category: string
{
    case Career = 'Career';
    case Health = 'Health';
    case Language = 'Language';
    case Life = 'Life';
    case Rest = 'Rest';

    /** Retired. Readable for historical entries, never assignable. */
    case Faith = 'Faith';

    /**
     * Cases that exist only to keep historical rows readable.
     *
     * @return list<self>
     */
    public static function retired(): array
    {
        return [self::Faith];
    }

    /** Whether a user may assign this category to an entry. */
    public function isSelectable(): bool
    {
        return ! in_array($this, self::retired(), true);
    }

    /**
     * Cases a user may assign, in declaration order.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->isSelectable(),
        ));
    }

    /**
     * Every stored value, including retired ones. Use on read paths.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Values accepted for new writes. Use on validation paths.
     *
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::selectable());
    }
}
