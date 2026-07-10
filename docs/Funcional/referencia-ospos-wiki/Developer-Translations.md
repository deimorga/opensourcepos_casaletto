[← Back to Development Index](Development-Index)

---

The translation process is made by two ways. In normal way translations are made in a web interface, and later this interface API integrates to github.

1. New strings must be added manually to each file in `app/Language` directory and then the interface will sync those new strings.
2. Already untranslated strings are not added manually to the files in language directory, must be made in the interface.

That interface is named [Weblate](https://translate.opensourcepos.org) - sign up to help translating this fine application. After registering you can subscribe to different languages and you will be notified once a new translation is added.

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/svg-badge.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

## Translation Status

- [Getting Started Usage](Getting-Started-usage)
- [Complete Feature List](Complete-feature-datasheet#complete-list-of-features)

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/multi-green.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

## Translations Guideline

See also [Development Index: Translations](Development-Index#always-use-translations-event-hardcoded-strings)

While not all guidelines will apply straight to all languages, we'd like to propose a few "Translation Guidelines" to be used and recommended for all translations:

- Titles follow capitalization rules for title format.  That is first Letter of each word capitalized except unimportant words and no punctuation is used (e.g., not "Change password." but instead "Change Password"). 
- Sentences follow sentence grammar rules for punctuation and capitalization specific to the language (e.g., not "one or more of the has processed sales or you are trying to delete your account" but instead "one or more of the has processed sales or you are trying to delete your account.").
- When sentences reference a field, it is referred to in the exact same format as the field (e.g., not "the title is a required field" but instead "Title is a required field").
- Use a consistent success/failure message format.  Currently, I see "The {field} was successfully updated" and in other places "{field} update successful", but I think we should stick to "{field} update successful" and {field} update failed" style messages.  There are three major reasons for this: Succinct translations, consistency and it allows us to remove dozens of translated lines because we only need one translated line for "update successful" and "update failed"  or "is a required field." and in the code we can call the field name and either update successful or failed.  Of course there will be exceptions where we want to add more information, and for those we can have a separate translation line.
- Gift Card(s) in the translation should be two words.  In the code it is one word, but English and most other languages it is two words.

# Manual Translation

**Note:** The preferred method is to use Weblate at https://translate.opensourcepos.org for translations.

In order to add a translation manually, the below steps should be followed (German is an example in this case):

- Edit the language files in the `app/Language/` directory, creating a new subdirectory for your language code (e.g., `app/Language/de/` for German)
- The language identifier must follow the standard locale codes (e.g., `de`, `de-DE`, `de-AT`, `de-CH`). Be careful to not simply select `de` because German from Germany is different from German in Switzerland
- Make a pull request based on the latest master. This pull request should contain the generated PHP language files
- Go to Store Config and Locale tab and select your language