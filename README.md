# MarkDOM 

A markdown parser that constructs perfectly formated valid DOM objects.

Well-formatted html can be precisely targeted with CSS without necessity of classes, id's and customized markup to accomodate stylesheets. This formatter intends to make HTML construction a priority, and as such, empowers broader interactive design using general markdown conventions. Further,  properly created DOM documents can be queried via xpath, enabling callbacks that can be utilized elegantly for further formatting (like adding class and id attributes, semantic revisions, etc).

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
- [ ] Parser callbacks (ie, encountering h2 inserts self and following p's into <section>)
  - [ ] render figure and figcaptions (using context callback from preceding todo)
  
  
#### Under Consideration
- [ ] Checkboxes and perhaps other inputs (select menus, input ranges... things that are useful for interactivity)