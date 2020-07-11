# DOMMarkDown 

A markdown parser that constructs valid, formated DOM objects.


## Goals

- terse code, no dependencies
- valid xHTML output
- formatted
- Queryable and mutable
- Parser callbacks

### TODO


- [x] render lists (including infinitely nested lists)
- [x] render headings
- [x] render links
- [x] render images
- [x] render Inline elements
- [ ] render blockquotes
- [x] render pre
- [ ] capture new lines in pre
- [ ] DOM to markdown converter
- [x] section-izing parser callback implementation
- [ ] design method to render figure and figcaptions (using context callback from preceding prob.. see note)
- [ ] replace ' with actual apostrophe
- [ ] deal with & and other entities
- [ ] think of syntax to post-render certain lists into definition lists


  
#### Under Consideration
- [x] Checkboxes and perhaps other inputs (select menus, input ranges... things that are useful for interactivity)

## Notes

  rather than bloat the code with complicated syntax to make things like figure and figcaption, consider contextual callbacks, ie., something like an <hr> followed by a <img> followed by a <p>text</p> would create a <figure><img/><figcaption>text</figcaption></figure>. This would be done using xpath + callbacks, ie addCallback('//hr/following-sibling::img/following-sibling::p, function($doc))
