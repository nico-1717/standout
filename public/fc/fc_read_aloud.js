export function ReadingAloud(sentence, lang) {
    
//simboli come ^ o / danno problemi perché indicano altro, basta metterci davanti \

if (lang == "it")
{
    sentence = sentence.replace(/\^2/g, " alla seconda ");
    sentence = sentence.replace(/\^3/g, " alla terza ");
    sentence = sentence.replace(/\^4/g, " alla quarta ");
    sentence = sentence.replace(/²/g, " alla seconda ");
    sentence = sentence.replace(/³/g, " alla terza ");
    sentence = sentence.replace(/⁴/g, " alla quarta "),
    
    sentence = sentence.replace(/->∞/g, " tende ad infinito ");
    sentence = sentence.replace(/∞/g, " infinito ");
    
    sentence = sentence.replace(/π\/2/g, " pi greco mezzi ");
    sentence = sentence.replace(/π\/4/g, " pi greco quarti ");
    sentence = sentence.replace(/π\/6/g, " pi greco sesti ");
    
    sentence = sentence.replace(/log/g, " log di ");
    sentence = sentence.replace(/ln/g, " log di ");
    
    sentence = sentence.replace(/arcsinx/g, " arc sin x ");
    sentence = sentence.replace(/arccosx/g, " arc cos x ");
    sentence = sentence.replace(/sinx/g, " sen x ");
    sentence = sentence.replace(/cosx/g, " cos x ");
    sentence = sentence.replace(/secx/g, " sichen ex ");
    sentence = sentence.replace(/cscx/g, " cosichen ex ");
    sentence = sentence.replace(/cotx/g, " cotangent ex ");

    sentence = sentence.replace(/\((.*?)\)'/g, "derivata di $1");
    sentence = sentence.replace(/\((.*?)\)’/g, "derivata di $1");        
    sentence = sentence.replace(/d\/dx/g, " derivata di ");
    
    sentence = sentence.replace(/∫/g, " integrale di ");
    sentence = sentence.replace(/dx/g, " in de x ");
    sentence = sentence.replace(/du/g, " in de u ");
    sentence = sentence.replace(/dθ/g, " in de teita ");
    sentence = sentence.replace(/u-sub/g, " iu sab ");

    sentence = sentence.replace(/=>/g, " diventa ");
    sentence = sentence.replace(/√/g, " radice di ");
    sentence = sentence.replace(/≥/g, " maggiore o uguale ");
    sentence = sentence.replace(/≤/g, " minore o uguale ");
    sentence = sentence.replace(/>/g, " maggiore ");
    sentence = sentence.replace(/</g, " minore ");
    sentence = sentence.replace(/≠/g, " diverso da ");
    sentence = sentence.replace(/∑/g, " sommatoria ");
    sentence = sentence.replace(/°/g, " gradi ");
    sentence = sentence.replace(/=/g, " uguale a ");
    sentence = sentence.replace(/-/g, " meno ");
    sentence = sentence.replace(/\//g, " fratto ");
}
if (lang == "en-US"){
    sentence = sentence.replace(/\^2/g, " square ");
    sentence = sentence.replace(/\^3/g, " cube ");
    sentence = sentence.replace(/\^4/g, " to the fourth power ");
    sentence = sentence.replace(/²/g, " square ");
    sentence = sentence.replace(/³/g, " cube ");
    sentence = sentence.replace(/⁴/g, " to the fourth power "),
    
    sentence = sentence.replace(/->∞/g, " to the infinity ");
    sentence = sentence.replace(/∞/g, " infinity ");
    
    sentence = sentence.replace(/π\/2/g, " pi greek half ");
    sentence = sentence.replace(/π\/4/g, " pi greek over four ");
    sentence = sentence.replace(/π\/6/g, " pi greek over six ");
    
    sentence = sentence.replace(/log/g, " l n of ");
    sentence = sentence.replace(/ln/g, " l n of ");
    
    sentence = sentence.replace(/arcsinx/g, " arc sin x ");
    sentence = sentence.replace(/arccosx/g, " arc cos x ");
    sentence = sentence.replace(/sinx/g, " sin x ");
    sentence = sentence.replace(/cosx/g, " cos x ");
    sentence = sentence.replace(/secx/g, " secant x ");
    sentence = sentence.replace(/cscx/g, " cosecant x ");
    sentence = sentence.replace(/cotx/g, " cotangent x ");

    sentence = sentence.replace(/\((.*?)\)'/g, " derivative of $1");
    sentence = sentence.replace(/\((.*?)\)’/g, " derivative of $1");        
    sentence = sentence.replace(/d\/dx/g, " derivate of ");
    
    sentence = sentence.replace(/∫/g, " integral of ");
    sentence = sentence.replace(/dx/g, " in d x ");
    sentence = sentence.replace(/du/g, " in d u ");
    sentence = sentence.replace(/dθ/g, " in d theta ");
    sentence = sentence.replace(/u-sub/g, " u sub ");

    sentence = sentence.replace(/=>/g, " get ");
    sentence = sentence.replace(/√/g, " square root of ");
    sentence = sentence.replace(/≥/g, " greater than or equal to ");
    sentence = sentence.replace(/≤/g, " less than or equal to ");
    sentence = sentence.replace(/>/g, " greater than ");
    sentence = sentence.replace(/</g, " less than ");
    sentence = sentence.replace(/≠/g, " not equal to ");
    sentence = sentence.replace(/∑/g, " summatiom ");
    sentence = sentence.replace(/°/g, " degree ");
    sentence = sentence.replace(/=/g, " equal to ");
    sentence = sentence.replace(/-/g, " minus ");
    sentence = sentence.replace(/\//g, " divided by "); 
}

        
        // Use the Web Speech API to speak the sentence
        const speech = new SpeechSynthesisUtterance(sentence);
        
        // Optionally, set voice properties
        speech.lang = lang; // Set language
        speech.pitch = 1;      // Set pitch (0 to 2)
        speech.rate = 1;       // Set speed/rate (0.1 to 10)
    
    
        // Speak the sentence
        window.speechSynthesis.speak(speech);

        console.log(sentence);
    
    }
    