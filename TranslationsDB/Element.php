<?php

namespace attitude\FlatYAMLDB;

use \attitude\FlatYAMLDB_Element;

use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

/**
 * Translation class that can read/write translations
 *
 * @todo: & store missing strings
 */
class TranslationsDB_Element extends FlatYAMLDB_Element
{
    const ZERO     = 'zero';
    const ONE      = 'one';
    const TWO      = 'two';
    const FEW      = 'few';
    const MANY     = 'many';
    const OTHER    = 'other';
    const FRACTION = 'fraction';

    /**
     * @var bool Holds status (translations source needs to be updated?)
     */
    private $is_dirty = false;

    protected function addData($data)
    {
        foreach ($data as $k => &$v) {
            $this->data[$k] = $v;
        }

        return $this;
    }

    /**
     *
     */
    public function translate($one='', $other='', $count=0, $offset=0)
    {
        // The $counts could be float. In Slovak (and probably in more languages)
        // the singular, plural forms are different for whole numbers (int) to
        // those with fractions (float).
        //
        // A receipt example:
        //
        // English: 1 potato, 2 potatoes, 5 potatoes, 2.5 of a potato
        // Slovak:  1 zemiak, 2 zamiaky, 5 zemiakov, 2.5 zemiaku
        //
        if (!is_string($one) || !is_string($other) || !(is_int($count) || is_float($count)) || !is_int($offset)) {
            throw new HTTPException(500, 'Incorrect input values for translation.');
        }

        // Seak is based on `other` form (for pluralisations) and on `one`
        // for direct string translations.
        if ($other==='') {
            $one    = $key = trim($one);
            $other  = false;
            $count  = false;
            $offset = false;
        } else {
            $key = $other = trim($other);
            $one = trim($one);
        }

        // Get global locale
        $locale = DependencyContainer::get('global::language.locale');

        // Get translation forms
        try {
            $translation_forms = $this->getTranslationForms($key, $locale);
        } catch (HTTPException $e) {
            // Not in dictionary, fast die with defaults
            if ($count===1 && !empty($one)) {
                return $one;
            }

            if (!$other) {
                return $one;
            }

            return $other;
        }

        // If a simple string translation or explicit number rule such as
        // 1, 2, 3... is defined, just use it.
        if (is_string($translation_forms)) {
            return $translation_forms;
        } elseif ($count && array_key_exists($count, $translation_forms)) {
            return $translation_forms[$count];
        }

        // Otherwise, check it against pluralization rules.

        // Pluralization rules
        //
        // Please define function and use a DependencyContainer class hook.
        // Only first two letters of defined language locale in lowercase
        // are used to create a dynamic hook.
        //
        // Examples how the hook would look like for:
        //  - en-US, en-CA, en-AU > en: global::language.pluralRules.enSelect
        //  - de_AT, de-DE, de-CH > de: global::language.pluralRules.deSelect
        //  - sk_SK               > sk: global::language.pluralRules.skSelect
        //  - cs-CS               > cs: global::language.pluralRules.czSelect
        //  - HU                  > hu: global::language.pluralRules.huSelect
        //
        // If you are not sure how to write select function, see AngularJS
        // for inspiration: https://github.com/angular/angular.js/blob/master/i18n/closure/pluralRules.js
        //
        // HEADS UP: This class defines an extra constant (compared to
        // AngularJS): FRACTION
        //
        $select_hook = 'global::language.pluralRules.'.strtolower(substr($locale,0,2)).'Select';
        $select      = DependencyContainer::get($select_hook, self::defaultSelect())->__invoke($count);

        // var_dump($key, $locale, $translation_forms, $select);

        // An expected form exists. Done.
        if (isset($translation_forms[$select])) {
            return $translation_forms[$select];
        }

        // Fallbacks:

        // If others is translated, probably is bether than nothing:
        if (isset($translation_forms['other'])) {
            return $translation_forms['other'];
        }

        // Not even close, use defaults:
        if ($count===1 && !empty($one)) {
            return $one;
        }

        return $other;
    }

    /**
     * Default plural select rule.
     * @param  number n The count of items.
     * @return string   Default value.
     * @private
     */
    public function defaultSelect($n)
    {
        return self::OTHER;
    }

    /**
     * Lookup the phrase in dictionary
     *
     * Marks dictionary dirty when needed translation is missing and keys are
     * inserted.
     *
     */
    public function getTranslationForms($key, $locale)
    {
        // Record is not even in the dictionary
        if (!isset($this->data[$key])) {
            $this->data[$key] = array();
            $this->dirty = true;

            throw new HTTPException(404);
        }

        // There is a record, but empty
        if (empty($this->data[$key])) {
            throw new HTTPException(404);
        }

        // Found specific locale
        if (isset($this->data[$key][$locale])) {
            return $this->data[$key][$locale];
        }

        // Maybe there is comething to dig out later
        return $this->data[$key];
    }
}
