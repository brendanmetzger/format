# MarkDOM 

A markdown parser that constructs valid, formated DOM objects.

Well-formatted html can be precisely targeted with CSS without too much utilization of classes, id's or customized markup to accomodate stylesheets. This formatter intends to make HTML construction a priority, and as such, empowers broader interactive design using general markdown conventions. Further,  properly created DOM documents can be queried via xpath, enabling callbacks that can be utilized elegantly for further formatting (like adding class and id attributes, semantic revisions, etc).

## Goals

- minimum code, no dependencies
- valid xHTML output
- nicely formatted
- Queryable
- Semantically enhanced formatting
- Parser callbacks

### TODO

#### Needs to be addressed

- [x] render lists (including infinitely nested lists)
- [x] render headings
- [ ] render links
- [ ] render images
- [ ] render blockquotes
- [ ] render codeblocks
- [ ] Parser callbacks (ie, encountering h2 inserts self and following p's into &lt;section&gt;)
  - [ ] render figure and figcaptions (using context callback from preceding todo)
  
  
#### Under Consideration
- [ ] Checkboxes and perhaps other inputs (select menus, input ranges... things that are useful for interactivity)

## Notes

  rather than bloat the code with complicated syntax to make things like figure and figcaption, consider contextual callbacks, ie., something like an <hr> followed by a <img> followed by a <p>text</p> would create a <figure><img/><figcaption>text</figcaption></figure>. This would be done using xpath + callbacks, ie addCallback('//hr/following-sibling::img/following-sibling::p, function($doc))
