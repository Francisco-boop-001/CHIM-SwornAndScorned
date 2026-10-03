# Sworn & Scorned — page art brief

Part of the World of Drama-Llama. Must match the series:
`CHIM-MindPoisoning/assets/dashboard-art-source.png` (mountain whisper, llama peeking
behind rocks) and `CHIM-PrivateConversation-dev/server/assets/private-conversation-scene.png`
(tavern whisper, llama on the banner).

Series style: woodcut/linocut on aged cream paper; three inks only (black, rust red,
slate blue); fur cloaks with round brooches; snowy peaks and pines. The llama: side-swept
black emo fringe, half-lidded unimpressed eyes, red neckerchief.

## Concept: "The Broken Oath"

Snowy steps of a Nord mead hall at dusk. A man below offers an open hand (sworn); a woman
one step above, arms crossed, turns away (scorned). A snapped iron oath ring lies between
them. Two onlookers whisper by the door (nod to Mind Poisoning); one holds a notched tally
stick (the ledger). Hidden llama: one carved gable ornament on the roof is a llama head.

Target output: 1536×1024 source PNG at `assets/dashboard-art-source.png`; runtime WebP
for the plugin page, as MP does.

## Prompt

```text
Woodcut / linocut print illustration on aged cream parchment paper, limited three-ink palette: deep black, rust red, and muted slate blue; heavy carved line texture, visible paper grain and ink speckle, hand-printed look. Landscape 3:2, 1536x1024.

Scene: the snowy front steps of a large timber Nordic mead hall at dusk, in a mountain town of Skyrim. Carved wooden pillars, a steep roof with crossed gable ornaments, warm lantern glow from the doorway, snowy peaks and pine forest behind under a streaked slate-blue sky.

Foreground: a bearded Nord man in a fur-collared cloak with a round bronze brooch stands at the foot of the steps, holding out an open right hand toward a woman, earnest, half pleading. One step above him a Nord woman with braided hair and a rust-red fur cloak stands with arms crossed, chin raised, turning her face away and glancing back at him over her shoulder, cold and unforgiving. On the step between them, half sunk in the snow, lies an iron oath ring snapped into two pieces.

Background: beside the hall door, two townsfolk lean together whispering while watching the couple; one holds a notched wooden tally stick.

Hidden detail: at the peak of the roof, one of the carved wooden gable ornaments is not a dragon head but a llama head, carved in the same wood, with a side-swept emo fringe over one half-lidded, unimpressed eye and a small rust-red scarf tied around its neck. It is small and blends into the architecture.

Style matches a series of Nordic woodcut prints: dramatic, slightly melancholic, no text, no lettering, no logos, no modern elements, no glossy digital painting, no extra colors.
```

If the llama comes out wrong, generate the scene first, then inpaint only the gable
ornament (the method used for the Private Conversation banner llama).
