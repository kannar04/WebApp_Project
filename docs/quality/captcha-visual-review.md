# CAPTCHA visual review

Reviewed 24 generated PNGs across four batches, without consulting the saved answers. Scores below are subjective visual difficulty at the displayed 220 x 72 size, not OCR benchmark results.

| Version | Samples | Visual difficulty | Decision |
| --- | --- | --- | --- |
| Previous rendering | 6 | About 3/10 | Too easy to read immediately |
| Dense interference experiment | 6 | About 8-9/10 | Rejected as excessively obscured |
| Final rendering, first batch | 6 | About 6-7/10 | Accepted |
| Final rendering, fresh batch | 6 | About 6-7/10 | Accepted |

The final rendering adds vertical and horizontal wave distortion, irregular rotation and spacing, foreground curves similar in weight to character strokes, narrow pale cuts, partial arcs and speckles. It preserves smooth rendering through supersampling. The fresh final batch is in [captcha-visual-review.png](captcha-visual-review.png).

Session ownership, correct/wrong/expired answers, single-use verification, refresh invalidation, PNG headers, and login rejection checks passed after the rendering change. This review does not establish resistance to OCR or vision models as a security guarantee.
