# (DOM)arkdown 

A markdown parser that constructs perfectly formated valid DOM objects. From my experience, well-formatted html can be precisely targeted with CSS without the need to go crazy with classes, id's, and other very custom code. This formatter intends to make HTML construction a priority. With a properly created DOM document, xpath, and callbacks can be utilized elegantly for further formatting (like adding class and id attributes).

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