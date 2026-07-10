[← Back to Home](Home)

---

Find our [Weblate website here](https://translate.opensourcepos.org) and sign up to help translating this fine application. After registering you can subscribe to different languages and you will be notified once a new translation is added.

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/multi-green.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

## Translations Guideline

While not all guidelines will apply straight to all languages, we'd like to propose a few "Translation Guidelines" to be used and recommended for all translations:

- Titles follow capitalization rules for title format.  That is first Letter of each word capitalized except unimportant words and no punctuation is used (e.g., not "Change password." but instead "Change Password"). 
- Sentences follow sentence grammar rules for punctuation and capitalization specific to the language (e.g., not "one or more of the has processed sales or you are trying to delete your account" but instead "one or more of the has processed sales or you are trying to delete your account.").
- When sentences reference a field, it is referred to in the exact same format as the field (e.g., not "the title is a required field" but instead "Title is a required field").
- Use a consistent success/failure message format.  Currently, I see "The {field} was successfully updated" and in other places "{field} update successful", but I think we should stick to "{field} update successful" and {field} update failed" style messages.  There are three major reasons for this: Succinct translations, consistency and it allows us to remove dozens of translated lines because we only need one translated line for "update successful" and "update failed"  or "is a required field." and in the code we can call the field name and either update successful or failed.  Of course there will be exceptions where we want to add more information, and for those we can have a separate translation line.
- Gift Card(s) in the translation should be two words.  In the code it is one word, but English and most other languages it is two words.

# Translation in the "old way"

**Note:** The preferred method is to use Weblate at https://translate.opensourcepos.org for translations. The manual method below is kept for reference.

In order to add a translation manually, the below steps should be followed (German is an example in this case):

- Edit the language files in the `app/Language/` directory, creating a new subdirectory for your language code (e.g., `app/Language/de/` for German)
- The language identifier must follow the standard locale codes (e.g., `de`, `de-DE`, `de-AT`, `de-CH`). Be careful to not simply select `de` because German from Germany is different from German in Switzerland
- Make a pull request based on the latest master. This pull request should contain the generated PHP language files
- Go to Store Config and Locale tab and select your language