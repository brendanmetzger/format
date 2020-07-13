# Modeled MarkDown 

A markdown parser that constructs valid, formated DOM objects, allowing callbacks to re-render documents as desired.


## Goals

- terse code, no dependencies
- valid xHTML output
- formatted
- Queryable and mutable
- Parser callbacks

### TODO

- [ ] render blockquotes
- [ ] render nested lists
- [ ] render processing instructions
- [ ] capture new lines in pre
- [ ] capture links betwixt &lt; &gt;
- [ ] titles in anchors and image captures
- [ ] DOM back to markdown
- [ ] design method to render figure and figcaptions (using context callback from preceding prob.. see note)
- [ ] replace ' with actual apostrophe
- [ ] think of syntax for definition lists

- [x] render lists
- [x] render headings
- [x] render links
- [x] render images
- [x] render Inline elements
- [x] render pre
- [x] section-izing parser callback implementation
- [x] deal with & and other entities


  
#### Under Consideration
- [x] Checkboxes
- [ ] other inputs (select menus, input ranges... things that are useful for interactivity)

## Notes

> rather than bloat the code with complicated syntax to make things like figure and figcaption, consider contextual callbacks, ie., something like an &lt;hr&gt; followed by a &lt;img&gt; followed by a &lt;p&gt;text&lt;/p&gt; would create a &lt;figure&gt;&lt;img/&gt;&lt;figcaption&gt;text&lt;/figcaption&gt;&lt;/figure&gt;. This would be done using xpath + callbacks, ie addCallback('//hr/following-sibling::img/following-sibling::p, function($doc))
