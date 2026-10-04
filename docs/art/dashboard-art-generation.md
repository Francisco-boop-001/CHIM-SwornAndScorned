# The Broken Oath - generation record

Generated with the built-in imagegen tool from the owner's brief. The two existing series images were inspected before generation as style references. The first generated scene was subsequently edited with the same tool to correct the woman's lower-body stance after owner feedback. The gable llama needed no correction.

Source: `assets/dashboard-art-source.png`.
Runtime: `server/dashboard-art.webp`, encoded with Pillow at quality 90, method 6, from the source at its original dimensions.

The plugin page has not been implemented yet; the WebP is ready for its future image reference.

The original source before the stance correction is retained at `tasks/evidence/dashboard-art-before-stance-fix.png`.

## Original generation prompt

```text
Use case: illustration-story.
Asset: Sworn & Scorned plugin page art, part of World of Drama-Llama.
Create a NEW scene using the two reference images shown immediately above ONLY as series style references: reference 1 is the mountain whisper with the little llama behind rocks; reference 2 is the tavern whisper with the llama banner. Match their carved linework, faces, fur cloaks, round brooches, aged cream paper grain, limited inks, and the llama's specific face. Do not copy their compositions.

Woodcut / linocut print illustration on aged cream parchment paper, limited three-ink palette: deep black, rust red, and muted slate blue; heavy carved line texture, visible paper grain and ink speckle, hand-printed look. Landscape 3:2, exactly 1536x1024.

Scene: the snowy front steps of a large timber Nordic mead hall at dusk, in a mountain town of Skyrim. Carved wooden pillars, a steep roof with crossed gable ornaments, warm lantern glow from the doorway expressed with cream paper and rust ink only, snowy peaks and pine forest behind under a streaked slate-blue sky. Compose so the peak of the roof and gable ornaments remain fully visible within the image.

Foreground: a bearded Nord man in a fur-collared cloak with a round bronze brooch (rendered using cream and black, no metallic yellow ink) stands at the foot of the steps, holding out an open RIGHT hand toward a woman, earnest, half pleading. One step above him a Nord woman with braided hair and a rust-red fur cloak stands with arms clearly CROSSED, chin raised, turning her face away and glancing back at him over her shoulder, cold and unforgiving. Her elevated step is clearly visible. On the step between them, half sunk in the snow, lies ONE iron oath ring snapped into TWO separated curved pieces, visibly forming the two halves of a single broken ring. Give this ring enough visual clarity to read as a snapped oath ring.

Background: beside the hall door, exactly two townsfolk lean together whispering while watching the couple; one holds a clearly notched wooden tally stick, a slender flat wooden rod with several incised tally notches.

Hidden detail: at the peak of the roof, one of the carved wooden gable ornaments is not a dragon head but a LLAMA head with a long narrow camelid muzzle and tall ears, carved in the same wood. It has a SIDE-SWEPT BLACK EMO FRINGE falling across one eye, the other eye HALF-LIDDED and UNIMPRESSED, and a small RUST-RED NECKERCHIEF tied around its neck. It is small and blends into the architecture, a discoverable Easter egg. This is the same emo llama character as both series reference images, rendered as a gable carving rather than a living animal.

Style matches the supplied Nordic woodcut prints: dramatic, slightly melancholic. Flat carved hatching, no smooth painterly gradients. No text, no lettering, no logos, no modern elements, no glossy digital painting, no extra colors. Cream is the unprinted paper color, not an additional ink.
```

## Stance correction prompt

```text
Use case: precise-object-edit.
Edit the supplied Sworn & Scorned woodcut image. Correct ONLY the foreground woman's lower-body stance, from below her waist through the boots and their small contact area with the snowy step. Her current visible leg is stretched at an odd angle and her feet do not read as a stable anatomical standing pose.

Give her a natural, dignified, firm standing stance: two anatomically proportionate legs beneath her pelvis, knees and ankles aligned naturally, both boots resting flat on the SAME snowy tread one step above the man, comfortably about hip width apart. The feet and legs follow her turned-away torso naturally without a contorted hip, crossed legs, exaggerated stride, or strange bent knee. Her dark Nordic skirt/tunic should drape naturally down from her belt, with the lower calves and two boots emerging convincingly; it must read as a standing woman in medieval clothing. Preserve a believable contact shadow rendered in carved black ink at each boot. Adjust only the local lower tunic/cloak folds necessary to make the corrected anatomy clear. Keep the red cloak's flowing character.

STRICT INVARIANTS: Keep her face, angry unforgiving expression, chin raised, braided hair, crossed arms, upper torso and brooch exactly as they are. Keep the man and his offered open hand, the two halves of the snapped iron ring, the whispering onlookers and notched tally stick, the mead hall, roof ornaments including emo llama with red neckerchief, mountains, pines, sky, and all other scene elements unchanged. No new objects. Preserve composition, framing, original 1536x1024 landscape dimensions, coarse woodcut carving, aged cream paper texture and three inks: black, rust red, slate blue. No new colors or lettering. This is a targeted anatomy correction, not a redesign of the artwork.
```
