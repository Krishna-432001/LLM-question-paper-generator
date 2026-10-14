<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlossaryTerm;
use App\Models\Language;
use Illuminate\Database\Seeder;

/**
 * Starter English → Tamil terminology for State Board and TNPSC papers.
 *
 * NOTE: compiled from Tamil Nadu State Board textbook usage, but it has NOT
 * yet been checked line by line by a native Tamil reviewer. Have one verify
 * it — the translation checks enforce these terms, so a wrong entry here is
 * repeated in every paper. Corrections belong in the admin glossary screen.
 *
 * Entries are stored once, English → Tamil, the way the textbooks print them.
 * The translator reads them inverted for Tamil → English, so there is no
 * second copy to drift out of step.
 *
 * kind 'term' entries are given to the translator and checked afterwards
 * (V2). kind 'name' entries are proper nouns: masked before translation and
 * substituted with the fixed spelling afterwards, never translated.
 *
 * Words that are also everyday English with a different Tamil meaning are
 * deliberately left out ("bank" of a river, "capital" city, the verb "may",
 * "power" of a Governor): enforcing one rendering would fail correct
 * translations of the other sense. Their unambiguous compounds are included
 * instead ("central bank", "electric power").
 *
 * Seeding only ever adds missing entries. An entry a reviewer has corrected
 * or deactivated is left exactly as they left it.
 */
final class GlossarySeeder extends Seeder
{
    /** @var array<string, list<array{0: string, 1: string}>> */
    private const TERMS = [
        'physics' => [
            ['physics', 'இயற்பியல்'], ['force', 'விசை'], ['motion', 'இயக்கம்'],
            ['velocity', 'திசைவேகம்'], ['speed', 'வேகம்'], ['acceleration', 'முடுக்கம்'],
            ['mass', 'நிறை'], ['weight', 'எடை'], ['energy', 'ஆற்றல்'],
            ['kinetic energy', 'இயக்க ஆற்றல்'], ['potential energy', 'நிலை ஆற்றல்'],
            ['work done', 'செய்யப்பட்ட வேலை'], ['electric power', 'மின் திறன்'],
            ['pressure', 'அழுத்தம்'], ['density', 'அடர்த்தி'], ['friction', 'உராய்வு'],
            ['gravitation', 'ஈர்ப்பியல்'], ['gravity', 'புவியீர்ப்பு'], ['momentum', 'உந்தம்'],
            ['inertia', 'நிலைமம்'], ['displacement', 'இடப்பெயர்ச்சி'], ['distance', 'தொலைவு'],
            ['frequency', 'அதிர்வெண்'], ['wavelength', 'அலைநீளம்'], ['amplitude', 'வீச்சு'],
            ['sound', 'ஒலி'], ['echo', 'எதிரொலி'], ['ultrasonic', 'மீயொலி'],
            ['refraction', 'ஒளிவிலகல்'], ['reflection', 'எதிரொளிப்பு'], ['dispersion', 'நிறப்பிரிகை'],
            ['refractive index', 'ஒளிவிலகல் எண்'], ['total internal reflection', 'முழு அக எதிரொளிப்பு'],
            ['angle of incidence', 'படுகோணம்'], ['spectrum', 'நிறமாலை'],
            ['lens', 'லென்சு'], ['convex lens', 'குவி லென்சு'], ['concave lens', 'குழி லென்சு'],
            ['mirror', 'ஆடி'], ['concave mirror', 'குழி ஆடி'], ['convex mirror', 'குவி ஆடி'],
            ['focal length', 'குவியத் தொலைவு'], ['electric current', 'மின்னோட்டம்'],
            ['potential difference', 'மின்னழுத்த வேறுபாடு'], ['voltage', 'மின்னழுத்தம்'],
            ['electrical resistance', 'மின்தடை'], ['resistor', 'மின்தடையாக்கி'],
            ['conductor', 'கடத்தி'], ['semiconductor', 'குறைகடத்தி'], ['solar cell', 'சூரிய மின்கலம்'],
            ['electric charge', 'மின்னூட்டம்'], ['electric circuit', 'மின்சுற்று'],
            ['electric cell', 'மின்கலம்'], ['dry cell', 'உலர் மின்கலம்'], ['fuse', 'மின் உருகி'],
            ['generator', 'மின்னியற்றி'], ['transformer', 'மின்மாற்றி'], ['electric motor', 'மின் மோட்டார்'],
            ['magnet', 'காந்தம்'], ['magnetic field', 'காந்தப்புலம்'], ['electromagnet', 'மின்காந்தம்'],
            ['ammeter', 'அம்மீட்டர்'], ['voltmeter', 'வோல்ட்மீட்டர்'], ['galvanometer', 'கால்வனோமீட்டர்'],
            ['atom', 'அணு'], ['molecule', 'மூலக்கூறு'], ['electron', 'எலக்ட்ரான்'],
            ['proton', 'புரோட்டான்'], ['neutron', 'நியூட்ரான்'], ['atomic nucleus', 'அணுக்கரு'],
            ['radioactivity', 'கதிரியக்கம்'], ['nuclear fission', 'அணுக்கரு பிளவு'],
            ['nuclear fusion', 'அணுக்கரு இணைவு'], ['thermometer', 'வெப்பநிலைமானி'],
            ['temperature', 'வெப்பநிலை'], ['heat', 'வெப்பம்'], ['evaporation', 'ஆவியாதல்'],
            ['condensation', 'ஆவி சுருங்குதல்'], ['melting point', 'உருகுநிலை'],
            ['boiling point', 'கொதிநிலை'], ['specific heat capacity', 'தன் வெப்ப ஏற்புத்திறன்'],
            ['vacuum', 'வெற்றிடம்'], ['simple pendulum', 'தனி ஊசல்'], ['buoyancy', 'மிதப்பு விசை'],
            ['lever', 'நெம்புகோல்'], ['pulley', 'கப்பி'], ['inclined plane', 'சாய்தளம்'],
            ['centre of gravity', 'ஈர்ப்பு மையம்'], ['light year', 'ஒளி ஆண்டு'],
            ['optical fibre', 'ஒளி இழை'], ['microscope', 'நுண்ணோக்கி'], ['telescope', 'தொலைநோக்கி'],
        ],
        'chemistry' => [
            ['chemistry', 'வேதியியல்'], ['element', 'தனிமம்'], ['compound', 'சேர்மம்'],
            ['mixture', 'கலவை'], ['acid', 'அமிலம்'], ['metal', 'உலோகம்'], ['non-metal', 'அலோகம்'],
            ['metalloid', 'உலோகப்போலி'], ['alloy', 'உலோகக்கலவை'], ['oxidation', 'ஆக்ஸிஜனேற்றம்'],
            ['catalyst', 'வினைவேக மாற்றி'], ['chemical reaction', 'வேதிவினை'],
            ['chemical equation', 'வேதிச் சமன்பாடு'], ['valency', 'இணைதிறன்'],
            ['atomic number', 'அணு எண்'], ['mass number', 'நிறை எண்'], ['atomic mass', 'அணுநிறை'],
            ['isotope', 'ஐசோடோப்பு'], ['periodic table', 'தனிம வரிசை அட்டவணை'],
            ['solute', 'கரைபொருள்'], ['solvent', 'கரைப்பான்'], ['solubility', 'கரைதிறன்'],
            ['indicator', 'நிறங்காட்டி'], ['neutralisation', 'நடுநிலையாக்கல்'],
            ['neutralization', 'நடுநிலையாக்கல்'], ['hydrogen', 'ஹைட்ரஜன்'], ['oxygen', 'ஆக்ஸிஜன்'],
            ['nitrogen', 'நைட்ரஜன்'], ['carbon', 'கார்பன்'], ['carbon dioxide', 'கார்பன் டை ஆக்சைடு'],
            ['chlorine', 'குளோரின்'], ['sodium', 'சோடியம்'], ['calcium', 'கால்சியம்'],
            ['iron', 'இரும்பு'], ['copper', 'தாமிரம்'], ['gold', 'தங்கம்'], ['silver', 'வெள்ளி'],
            ['aluminium', 'அலுமினியம்'], ['zinc', 'துத்தநாகம்'], ['sulphur', 'கந்தகம்'],
            ['ammonia', 'அம்மோனியா'], ['methane', 'மீத்தேன்'], ['ozone', 'ஓசோன்'],
            ['polymer', 'பலபடி'], ['hydrocarbon', 'ஹைட்ரோகார்பன்'], ['ion', 'அயனி'],
            ['electrolysis', 'மின்னாற்பகுப்பு'], ['corrosion', 'அரிமானம்'], ['rusting', 'துருப்பிடித்தல்'],
            ['fermentation', 'நொதித்தல்'], ['distillation', 'வாலைவடித்தல்'], ['filtration', 'வடிகட்டுதல்'],
            ['sublimation', 'பதங்கமாதல்'], ['crystallisation', 'படிகமாக்கல்'],
            ['bleaching powder', 'சலவைத்தூள்'], ['baking soda', 'சமையல் சோடா'],
            ['washing soda', 'சலவை சோடா'], ['plaster of Paris', 'பாரிஸ் சாந்து'],
            ['covalent bond', 'சகப் பிணைப்பு'], ['ionic bond', 'அயனிப் பிணைப்பு'],
            ['noble gases', 'மந்த வாயுக்கள்'], ['exothermic reaction', 'வெப்ப உமிழ் வினை'],
            ['endothermic reaction', 'வெப்பம் கொள் வினை'],
        ],
        'biology' => [
            ['biology', 'உயிரியல்'], ['cell', 'செல்'], ['cell membrane', 'செல் சவ்வு'],
            ['cell wall', 'செல் சுவர்'], ['cytoplasm', 'சைட்டோபிளாசம்'], ['nucleus', 'உட்கரு'],
            ['mitochondria', 'மைட்டோகாண்ட்ரியா'], ['chloroplast', 'பசுங்கணிகம்'],
            ['chlorophyll', 'பச்சையம்'], ['ribosome', 'ரைபோசோம்'], ['photosynthesis', 'ஒளிச்சேர்க்கை'],
            ['respiration', 'சுவாசம்'], ['transpiration', 'நீராவிப்போக்கு'], ['digestion', 'செரித்தல்'],
            ['excretion', 'கழிவுநீக்கம்'], ['enzyme', 'நொதி'], ['hormone', 'ஹார்மோன்'],
            ['protein', 'புரதம்'], ['carbohydrate', 'கார்போஹைட்ரேட்'], ['fat', 'கொழுப்பு'],
            ['vitamin', 'வைட்டமின்'], ['nutrition', 'ஊட்டச்சத்து'], ['balanced diet', 'சரிவிகித உணவு'],
            ['malnutrition', 'ஊட்டச்சத்துக் குறைபாடு'], ['blood', 'இரத்தம்'],
            ['red blood cells', 'இரத்த சிவப்பணுக்கள்'], ['white blood cells', 'இரத்த வெள்ளையணுக்கள்'],
            ['platelets', 'இரத்தத் தட்டுகள்'], ['plasma', 'பிளாஸ்மா'], ['blood pressure', 'இரத்த அழுத்தம்'],
            ['blood group', 'இரத்த வகை'], ['heart', 'இதயம்'], ['lungs', 'நுரையீரல்கள்'],
            ['kidney', 'சிறுநீரகம்'], ['liver', 'கல்லீரல்'], ['brain', 'மூளை'], ['stomach', 'இரைப்பை'],
            ['small intestine', 'சிறுகுடல்'], ['large intestine', 'பெருங்குடல்'], ['pancreas', 'கணையம்'],
            ['skeleton', 'எலும்புக்கூடு'], ['muscle', 'தசை'], ['bone', 'எலும்பு'],
            ['nervous system', 'நரம்பு மண்டலம்'], ['digestive system', 'செரிமான மண்டலம்'],
            ['respiratory system', 'சுவாச மண்டலம்'], ['circulatory system', 'இரத்த ஓட்ட மண்டலம்'],
            ['excretory system', 'கழிவுநீக்க மண்டலம்'], ['sense organs', 'புலன் உறுப்புகள்'],
            ['artery', 'தமனி'], ['vein', 'சிரை'], ['capillary', 'தந்துகி'], ['neuron', 'நியூரான்'],
            ['gene', 'மரபணு'], ['chromosome', 'குரோமோசோம்'], ['heredity', 'பாரம்பரியம்'],
            ['evolution', 'பரிணாமம்'], ['mutation', 'திடீர்மாற்றம்'], ['bacteria', 'பாக்டீரியா'],
            ['virus', 'வைரஸ்'], ['fungi', 'பூஞ்சைகள்'], ['algae', 'பாசிகள்'],
            ['protozoa', 'புரோட்டோசோவா'], ['vaccine', 'தடுப்பூசி'], ['immunity', 'நோய்த்தடைக்காப்பு'],
            ['antibiotic', 'நுண்ணுயிர் எதிர்ப்பொருள்'], ['antibody', 'எதிர்ப்பொருள்'],
            ['infection', 'தொற்று'], ['deficiency disease', 'குறைபாட்டு நோய்'],
            ['diabetes', 'நீரிழிவு நோய்'], ['tuberculosis', 'காசநோய்'], ['malaria', 'மலேரியா'],
            ['anaemia', 'இரத்தசோகை'], ['goitre', 'முன்கழுத்துக் கழலை'], ['night blindness', 'மாலைக்கண் நோய்'],
            ['insulin', 'இன்சுலின்'], ['pollination', 'மகரந்தச் சேர்க்கை'], ['pollen', 'மகரந்தம்'],
            ['seed', 'விதை'], ['germination', 'முளைத்தல்'], ['fertilisation', 'கருவுறுதல்'],
            ['root', 'வேர்'], ['leaf', 'இலை'], ['flower', 'மலர்'], ['fruit', 'கனி'],
            ['xylem', 'சைலம்'], ['phloem', 'புளோயம்'], ['stomata', 'இலைத்துளைகள்'],
            ['tissue', 'திசு'], ['organ', 'உறுப்பு'], ['vertebrate', 'முதுகெலும்பி'],
            ['invertebrate', 'முதுகெலும்பில்லாதவை'], ['mammal', 'பாலூட்டி'], ['reptile', 'ஊர்வன'],
            ['amphibian', 'இருவாழ்வி'], ['herbivore', 'தாவர உண்ணி'], ['carnivore', 'ஊன் உண்ணி'],
            ['omnivore', 'அனைத்துண்ணி'], ['decomposer', 'சிதைப்பவை'], ['first aid', 'முதலுதவி'],
            ['pulse rate', 'நாடித்துடிப்பு'],
        ],
        'environment' => [
            ['environment', 'சுற்றுச்சூழல்'], ['ecosystem', 'சூழ்நிலை மண்டலம்'],
            ['food chain', 'உணவுச் சங்கிலி'], ['food web', 'உணவு வலை'], ['biodiversity', 'பல்லுயிர்த்தன்மை'],
            ['pollution', 'மாசுபாடு'], ['air pollution', 'காற்று மாசுபாடு'],
            ['water pollution', 'நீர் மாசுபாடு'], ['global warming', 'புவி வெப்பமயமாதல்'],
            ['greenhouse effect', 'பசுமை இல்ல விளைவு'], ['acid rain', 'அமில மழை'],
            ['ozone layer', 'ஓசோன் படலம்'], ['deforestation', 'காடழிப்பு'], ['afforestation', 'காடு வளர்ப்பு'],
            ['renewable energy', 'புதுப்பிக்கத்தக்க ஆற்றல்'], ['solar energy', 'சூரிய ஆற்றல்'],
            ['wind energy', 'காற்று ஆற்றல்'], ['fossil fuels', 'புதைபடிவ எரிபொருள்கள்'],
            ['biogas', 'உயிர்வாயு'], ['wildlife sanctuary', 'வனவிலங்கு சரணாலயம்'],
            ['national park', 'தேசியப் பூங்கா'], ['biosphere reserve', 'உயிர்க்கோளக் காப்பகம்'],
            ['endangered species', 'அழிவின் விளிம்பிலுள்ள உயிரினங்கள்'],
            ['organic farming', 'இயற்கை வேளாண்மை'], ['fertiliser', 'உரம்'],
            ['pesticide', 'பூச்சிக்கொல்லி'], ['manure', 'எரு'], ['vermicompost', 'மண்புழு உரம்'],
        ],
        'polity' => [
            ['Constitution', 'அரசியலமைப்பு'], ['Preamble', 'முகவுரை'],
            ['Fundamental Rights', 'அடிப்படை உரிமைகள்'], ['Fundamental Duties', 'அடிப்படைக் கடமைகள்'],
            ['Directive Principles of State Policy', 'அரசு நெறிமுறைக் கோட்பாடுகள்'],
            ['Parliament', 'நாடாளுமன்றம்'], ['Lok Sabha', 'மக்களவை'], ['Rajya Sabha', 'மாநிலங்களவை'],
            ['President', 'குடியரசுத் தலைவர்'], ['Vice-President', 'குடியரசுத் துணைத் தலைவர்'],
            ['Prime Minister', 'பிரதமர்'], ['Chief Minister', 'முதலமைச்சர்'], ['Governor', 'ஆளுநர்'],
            ['Speaker', 'சபாநாயகர்'], ['Council of Ministers', 'அமைச்சர்கள் குழு'],
            ['Cabinet', 'அமைச்சரவை'], ['Legislative Assembly', 'சட்டமன்றப் பேரவை'],
            ['Legislative Council', 'சட்ட மேலவை'], ['Supreme Court', 'உச்ச நீதிமன்றம்'],
            ['High Court', 'உயர் நீதிமன்றம்'], ['judiciary', 'நீதித்துறை'], ['election', 'தேர்தல்'],
            ['Election Commission', 'தேர்தல் ஆணையம்'], ['universal adult franchise', 'வயது வந்தோர் வாக்குரிமை'],
            ['citizenship', 'குடியுரிமை'], ['democracy', 'மக்களாட்சி'], ['federalism', 'கூட்டாட்சி'],
            ['secularism', 'மதச்சார்பின்மை'], ['sovereignty', 'இறையாண்மை'], ['republic', 'குடியரசு'],
            ['socialism', 'சமதர்மம்'], ['amendment', 'திருத்தம்'],
            ['constitutional amendment', 'அரசியலமைப்புத் திருத்தம்'], ['emergency', 'நெருக்கடி நிலை'],
            ['panchayat', 'ஊராட்சி'], ['gram sabha', 'கிராம சபை'], ['municipality', 'நகராட்சி'],
            ['municipal corporation', 'மாநகராட்சி'], ['local self-government', 'உள்ளாட்சி அமைப்பு'],
            ['Union Territory', 'யூனியன் பிரதேசம்'],
            ['Comptroller and Auditor General', 'தலைமைக் கணக்குத் தணிக்கையாளர்'],
            ['Attorney General', 'தலைமை வழக்கறிஞர்'], ['Finance Commission', 'நிதி ஆணையம்'],
            ['writ', 'நீதிப்பேராணை'], ['right to equality', 'சமத்துவ உரிமை'],
            ['right to education', 'கல்வி உரிமை'], ['right to information', 'தகவல் அறியும் உரிமை'],
            // Money Bill (Art. 110) is பண மசோதா; நிதி மசோதா is a Finance Bill.
            ['human rights', 'மனித உரிமைகள்'], ['money bill', 'பண மசோதா'],
            ['adjournment motion', 'ஒத்திவைப்புத் தீர்மானம்'],
            ['ordinance', 'அவசரச் சட்டம்'], ['no-confidence motion', 'நம்பிக்கையில்லாத் தீர்மானம்'],
            ['Planning Commission', 'திட்டக் குழு'], ['political party', 'அரசியல் கட்சி'],
            ['opposition party', 'எதிர்க்கட்சி'], ['constituency', 'தொகுதி'], ['ballot', 'வாக்குச்சீட்டு'],
            ['voter', 'வாக்காளர்'], ['reservation', 'இட ஒதுக்கீடு'],
            ['Scheduled Castes', 'பட்டியல் சாதியினர்'], ['Scheduled Tribes', 'பழங்குடியினர்'],
        ],
        'geography' => [
            ['geography', 'புவியியல்'], ['latitude', 'அட்சரேகை'], ['longitude', 'தீர்க்கரேகை'],
            ['equator', 'நிலநடுக்கோடு'], ['Tropic of Cancer', 'கடகரேகை'],
            ['Tropic of Capricorn', 'மகரரேகை'], ['Prime Meridian', 'முதன்மைத் தீர்க்கரேகை'],
            ['hemisphere', 'அரைக்கோளம்'], ['continent', 'கண்டம்'], ['ocean', 'பெருங்கடல்'],
            ['island', 'தீவு'], ['peninsula', 'தீபகற்பம்'], ['plateau', 'பீடபூமி'],
            ['plains', 'சமவெளிகள்'], ['mountain', 'மலை'], ['valley', 'பள்ளத்தாக்கு'],
            ['delta', 'டெல்டா'], ['estuary', 'கழிமுகம்'], ['tributary', 'துணையாறு'],
            ['monsoon', 'பருவக்காற்று'], ['south-west monsoon', 'தென்மேற்குப் பருவக்காற்று'],
            ['north-east monsoon', 'வடகிழக்குப் பருவக்காற்று'], ['climate', 'காலநிலை'],
            ['weather', 'வானிலை'], ['rainfall', 'மழைப்பொழிவு'], ['humidity', 'ஈரப்பதம்'],
            // The atmosphere and earth layers (troposphere, stratosphere,
            // lithosphere) are left out until their Std 9 terms are checked.
            ['atmosphere', 'வளிமண்டலம்'],
            ['hydrosphere', 'நீர்க்கோளம்'], ['biosphere', 'உயிர்க்கோளம்'],
            ['earthquake', 'நிலநடுக்கம்'], ['volcano', 'எரிமலை'], ['tsunami', 'ஆழிப்பேரலை'],
            ['cyclone', 'புயல்'], ['flood', 'வெள்ளம்'], ['drought', 'வறட்சி'], ['soil', 'மண்'],
            ['alluvial soil', 'வண்டல் மண்'], ['black soil', 'கரிசல் மண்'], ['red soil', 'செம்மண்'],
            ['laterite soil', 'சரளை மண்'], ['soil erosion', 'மண் அரிப்பு'], ['erosion', 'அரிப்பு'],
            ['irrigation', 'நீர்ப்பாசனம்'], ['dam', 'அணை'], ['reservoir', 'நீர்த்தேக்கம்'],
            ['forest', 'காடு'], ['evergreen forest', 'பசுமைமாறாக் காடுகள்'],
            ['deciduous forest', 'இலையுதிர் காடுகள்'], ['mangrove forest', 'அலையாத்திக் காடுகள்'],
            ['solar system', 'சூரியக் குடும்பம்'], ['planet', 'கோள்'], ['satellite', 'துணைக்கோள்'],
            ['artificial satellite', 'செயற்கைக்கோள்'], ['galaxy', 'விண்மீன் திரள்'], ['star', 'விண்மீன்'],
            ['comet', 'வால் நட்சத்திரம்'], ['solar eclipse', 'சூரிய கிரகணம்'],
            ['lunar eclipse', 'சந்திர கிரகணம்'], ['tide', 'ஓதம்'], ['population', 'மக்கள்தொகை'],
            ['population density', 'மக்களடர்த்தி'], ['literacy rate', 'எழுத்தறிவு விகிதம்'],
            ['sex ratio', 'பாலின விகிதம்'], ['migration', 'இடம்பெயர்தல்'],
            ['urbanisation', 'நகரமயமாக்கல்'], ['census', 'மக்கள்தொகை கணக்கெடுப்பு'],
            ['agriculture', 'வேளாண்மை'], ['crop', 'பயிர்'], ['kharif season', 'காரீப் பருவம்'],
            ['rabi season', 'ராபி பருவம்'], ['paddy', 'நெல்'], ['wheat', 'கோதுமை'],
            ['sugarcane', 'கரும்பு'], ['cotton', 'பருத்தி'], ['tea', 'தேயிலை'], ['coffee', 'காபி'],
            ['groundnut', 'நிலக்கடலை'], ['millets', 'சிறுதானியங்கள்'], ['pulses', 'பருப்பு வகைகள்'],
            ['fisheries', 'மீன்வளம்'], ['coal', 'நிலக்கரி'], ['petroleum', 'பெட்ரோலியம்'],
            ['natural gas', 'இயற்கை எரிவாயு'], ['iron ore', 'இரும்புத் தாது'], ['bauxite', 'பாக்சைட்'],
            ['port', 'துறைமுகம்'], ['national highway', 'தேசிய நெடுஞ்சாலை'], ['map', 'நிலவரைபடம்'],
        ],
        'history' => [
            ['history', 'வரலாறு'], ['inscription', 'கல்வெட்டு'], ['coin', 'நாணயம்'],
            ['temple', 'கோயில்'], ['dynasty', 'அரசவம்சம்'], ['empire', 'பேரரசு'],
            ['Sangam age', 'சங்க காலம்'], ['Sangam literature', 'சங்க இலக்கியம்'],
            ['Indus Valley Civilisation', 'சிந்துவெளி நாகரிகம்'], ['Vedic period', 'வேதகாலம்'],
            ['Buddhism', 'பௌத்தம்'], ['Jainism', 'சமணம்'], ['Mughal Empire', 'முகலாயப் பேரரசு'],
            ['Delhi Sultanate', 'டெல்லி சுல்தானியம்'], ['Vijayanagara Empire', 'விஜயநகரப் பேரரசு'],
            ['freedom struggle', 'விடுதலைப் போராட்டம்'], ['independence', 'சுதந்திரம்'],
            ['Non-Cooperation Movement', 'ஒத்துழையாமை இயக்கம்'],
            ['Civil Disobedience Movement', 'சட்டமறுப்பு இயக்கம்'],
            ['Quit India Movement', 'வெள்ளையனே வெளியேறு இயக்கம்'],
            ['Salt Satyagraha', 'உப்புச் சத்தியாகிரகம்'], ['Dandi March', 'தண்டி யாத்திரை'],
            ['Salt March', 'உப்பு யாத்திரை'],
            ['Swadeshi Movement', 'சுதேசி இயக்கம்'], ['Partition of Bengal', 'வங்கப் பிரிவினை'],
            ['Self-Respect Movement', 'சுயமரியாதை இயக்கம்'], ['Sepoy Mutiny', 'சிப்பாய் கலகம்'],
            ['Governor-General', 'தலைமை ஆளுநர்'], ['Viceroy', 'வைஸ்ராய்'],
            ['Battle of Plassey', 'பிளாசிப் போர்'], ['Poligars', 'பாளையக்காரர்கள்'],
            ['Renaissance', 'மறுமலர்ச்சி'], ['reform movement', 'சீர்திருத்த இயக்கம்'],
            ['social reformer', 'சமூகச் சீர்திருத்தவாதி'], ['untouchability', 'தீண்டாமை'],
            ['caste', 'சாதி'], ['Bhakti movement', 'பக்தி இயக்கம்'], ['Alvars', 'ஆழ்வார்கள்'],
            ['Nayanmars', 'நாயன்மார்கள்'], ['Green Revolution', 'பசுமைப் புரட்சி'],
            ['White Revolution', 'வெண்மைப் புரட்சி'], ['French Revolution', 'பிரெஞ்சுப் புரட்சி'],
            ['Industrial Revolution', 'தொழிற்புரட்சி'],
        ],
        'economy' => [
            ['economy', 'பொருளாதாரம்'], ['economics', 'பொருளியல்'], ['inflation', 'பணவீக்கம்'],
            ['deflation', 'பணவாட்டம்'], ['Gross Domestic Product', 'மொத்த உள்நாட்டு உற்பத்தி'],
            ['national income', 'நாட்டு வருமானம்'], ['per capita income', 'தலா வருமானம்'],
            ['budget', 'வரவு செலவுத் திட்டம்'], ['fiscal deficit', 'நிதிப் பற்றாக்குறை'],
            ['tax', 'வரி'], ['direct tax', 'நேர்முக வரி'], ['indirect tax', 'மறைமுக வரி'],
            ['income tax', 'வருமான வரி'], ['Goods and Services Tax', 'சரக்கு மற்றும் சேவை வரி'],
            ['poverty', 'வறுமை'], ['poverty line', 'வறுமைக் கோடு'], ['unemployment', 'வேலையின்மை'],
            ['Five Year Plan', 'ஐந்தாண்டுத் திட்டம்'], ['law of demand', 'தேவை விதி'],
            ['law of supply', 'அளிப்பு விதி'], ['market', 'சந்தை'], ['monopoly', 'முற்றுரிமை'],
            ['central bank', 'மைய வங்கி'], ['commercial bank', 'வணிக வங்கி'],
            ['interest rate', 'வட்டி விகிதம்'], ['loan', 'கடன்'], ['savings', 'சேமிப்பு'],
            ['investment', 'முதலீடு'], ['foreign exchange', 'அந்நியச் செலாவணி'],
            ['export', 'ஏற்றுமதி'], ['import', 'இறக்குமதி'],
            ['balance of payments', 'செலுத்துநிலைச் சமநிலை'], ['globalisation', 'உலகமயமாக்கல்'],
            ['liberalisation', 'தாராளமயமாக்கல்'], ['privatisation', 'தனியார்மயமாக்கல்'],
            ['public sector', 'பொதுத்துறை'], ['private sector', 'தனியார் துறை'],
            ['primary sector', 'முதன்மைத் துறை'], ['self-help group', 'சுய உதவிக் குழு'],
            ['cooperative society', 'கூட்டுறவுச் சங்கம்'],
            ['public distribution system', 'பொது விநியோக முறை'], ['ration card', 'குடும்ப அட்டை'],
            ['minimum support price', 'குறைந்தபட்ச ஆதரவு விலை'], ['microfinance', 'நுண்கடன்'],
            ['consumer', 'நுகர்வோர்'], ['consumer rights', 'நுகர்வோர் உரிமைகள்'],
            ['stock exchange', 'பங்குச் சந்தை'], ['currency', 'நாணயம்'], ['revenue', 'வருவாய்'],
            ['subsidy', 'மானியம்'], ['insurance', 'காப்பீடு'], ['pension', 'ஓய்வூதியம்'],
        ],
        'aptitude' => [
            ['simple interest', 'தனிவட்டி'], ['compound interest', 'கூட்டுவட்டி'], ['profit', 'இலாபம்'],
            ['profit and loss', 'இலாபம் மற்றும் நட்டம்'], ['cost price', 'அடக்க விலை'],
            ['selling price', 'விற்பனை விலை'], ['discount', 'தள்ளுபடி'], ['marked price', 'குறித்த விலை'],
            ['percentage', 'சதவீதம்'], ['ratio', 'விகிதம்'], ['proportion', 'விகிதசமம்'],
            ['average', 'சராசரி'], ['perimeter', 'சுற்றளவு'], ['volume', 'கனஅளவு'],
            ['circle', 'வட்டம்'], ['radius', 'ஆரம்'], ['diameter', 'விட்டம்'],
            ['circumference', 'பரிதி'], ['triangle', 'முக்கோணம்'], ['rectangle', 'செவ்வகம்'],
            ['cylinder', 'உருளை'], ['cone', 'கூம்பு'], ['sphere', 'கோளம்'], ['equation', 'சமன்பாடு'],
            ['prime number', 'பகா எண்'], ['composite number', 'பகு எண்'],
            ['even number', 'இரட்டைப்படை எண்'], ['odd number', 'ஒற்றைப்படை எண்'],
            ['natural numbers', 'இயல் எண்கள்'], ['whole numbers', 'முழு எண்கள்'],
            ['integers', 'முழுக்கள்'], ['fraction', 'பின்னம்'], ['decimal', 'தசம எண்'],
            ['highest common factor', 'மீப்பெரு பொது வகுத்தி'],
            ['least common multiple', 'மீச்சிறு பொது மடங்கு'], ['square root', 'வர்க்கமூலம்'],
            ['cube root', 'கனமூலம்'], ['probability', 'நிகழ்தகவு'], ['median', 'இடைநிலை அளவு'],
            ['number series', 'எண் தொடர்'], ['blood relation', 'இரத்த உறவு'],
            ['calendar', 'நாள்காட்டி'], ['clock', 'கடிகாரம்'], ['angle', 'கோணம்'],
            ['right angle', 'செங்கோணம்'], ['parallel lines', 'இணைகோடுகள்'],
        ],
        // "May" is left out on purpose: as a term it would demand மே in every
        // translation of the verb "may".
        'calendar' => [
            ['January', 'ஜனவரி'], ['February', 'பிப்ரவரி'], ['March', 'மார்ச்'], ['April', 'ஏப்ரல்'],
            ['June', 'ஜூன்'], ['July', 'ஜூலை'], ['August', 'ஆகஸ்ட்'], ['September', 'செப்டம்பர்'],
            ['October', 'அக்டோபர்'], ['November', 'நவம்பர்'], ['December', 'டிசம்பர்'],
            ['Monday', 'திங்கள்'], ['Tuesday', 'செவ்வாய்'], ['Wednesday', 'புதன்'],
            ['Thursday', 'வியாழன்'], ['Friday', 'வெள்ளி'], ['Saturday', 'சனி'], ['Sunday', 'ஞாயிறு'],
        ],
        'exam' => [
            ['statement', 'கூற்று'], ['assertion', 'கூற்று'], ['match the following', 'பொருத்துக'],
            ['none of the above', 'மேற்கண்ட எதுவுமில்லை'], ['all of the above', 'மேற்கண்ட அனைத்தும்'],
        ],
        // Phrases that apply in any subject. Each one also stops a subject's
        // single word from being forced inside it ("force" in Air Force,
        // "mass" in mass media).
        'general' => [
            ['Air Force', 'விமானப்படை'], ['mass media', 'மக்கள் ஊடகங்கள்'],
        ],
    ];

    /** @var array<string, list<array{0: string, 1: string}>> */
    private const NAMES = [
        'place' => [
            ['Tamil Nadu', 'தமிழ்நாடு'], ['Chennai', 'சென்னை'], ['Madras', 'மதராஸ்'],
            ['India', 'இந்தியா'], ['Delhi', 'டெல்லி'], ['New Delhi', 'புது டெல்லி'],
            ['Madurai', 'மதுரை'], ['Coimbatore', 'கோயம்புத்தூர்'], ['Tiruchirappalli', 'திருச்சிராப்பள்ளி'],
            ['Thanjavur', 'தஞ்சாவூர்'], ['Kanchipuram', 'காஞ்சிபுரம்'], ['Kanyakumari', 'கன்னியாகுமரி'],
            ['Rameswaram', 'இராமேஸ்வரம்'], ['Tirunelveli', 'திருநெல்வேலி'], ['Salem', 'சேலம்'],
            ['Vellore', 'வேலூர்'], ['Thoothukudi', 'தூத்துக்குடி'], ['Erode', 'ஈரோடு'],
            ['Ooty', 'உதகமண்டலம்'], ['Kodaikanal', 'கொடைக்கானல்'], ['Puducherry', 'புதுச்சேரி'],
            ['Mamallapuram', 'மாமல்லபுரம்'], ['Mahabalipuram', 'மாமல்லபுரம்'], ['Keezhadi', 'கீழடி'],
            ['Adichanallur', 'ஆதிச்சநல்லூர்'], ['Nilgiris', 'நீலகிரி'], ['Kerala', 'கேரளா'],
            ['Karnataka', 'கர்நாடகா'], ['Andhra Pradesh', 'ஆந்திரப் பிரதேசம்'], ['Sri Lanka', 'இலங்கை'],
            ['Mumbai', 'மும்பை'], ['Kolkata', 'கொல்கத்தா'], ['Western Ghats', 'மேற்குத் தொடர்ச்சி மலைகள்'],
            ['Eastern Ghats', 'கிழக்குத் தொடர்ச்சி மலைகள்'], ['Himalayas', 'இமயமலை'],
            ['Bay of Bengal', 'வங்காள விரிகுடா'], ['Arabian Sea', 'அரபிக் கடல்'],
            ['Indian Ocean', 'இந்தியப் பெருங்கடல்'], ['Palk Strait', 'பாக் நீர்ச்சந்தி'],
        ],
        'river' => [
            ['Cauvery', 'காவிரி'], ['Kaveri', 'காவிரி'], ['Vaigai', 'வைகை'],
            ['Thamirabarani', 'தாமிரபரணி'], ['Palar', 'பாலாறு'], ['Bhavani', 'பவானி'],
            ['Ganga', 'கங்கை'], ['Godavari', 'கோதாவரி'], ['Narmada', 'நர்மதை'],
            ['Brahmaputra', 'பிரம்மபுத்திரா'], ['Yamuna', 'யமுனை'], ['Indus', 'சிந்து'],
        ],
        'person' => [
            ['Mahatma Gandhi', 'மகாத்மா காந்தி'], ['Gandhi', 'காந்தி'], ['Bharathiyar', 'பாரதியார்'],
            ['Subramania Bharati', 'சுப்பிரமணிய பாரதி'], ['Periyar', 'பெரியார்'],
            ['Kamarajar', 'காமராஜர்'], ['Kamaraj', 'காமராஜர்'], ['Ambedkar', 'அம்பேத்கர்'],
            ['Nehru', 'நேரு'], ['Jawaharlal Nehru', 'ஜவஹர்லால் நேரு'],
            ['Subhas Chandra Bose', 'சுபாஷ் சந்திர போஸ்'], ['Bhagat Singh', 'பகத் சிங்'],
            ['V.O. Chidambaram', 'வ.உ.சிதம்பரனார்'], ['Velu Nachiyar', 'வேலு நாச்சியார்'],
            ['Veerapandiya Kattabomman', 'வீரபாண்டிய கட்டபொம்மன்'], ['Kattabomman', 'கட்டபொம்மன்'],
            ['Marudhu Brothers', 'மருது சகோதரர்கள்'], ['Puli Thevar', 'பூலித்தேவன்'],
            ['Thiruvalluvar', 'திருவள்ளுவர்'], ['Avvaiyar', 'ஔவையார்'], ['Ilango Adigal', 'இளங்கோ அடிகள்'],
            ['Kambar', 'கம்பர்'], ['Bharathidasan', 'பாரதிதாசன்'], ['Annadurai', 'அண்ணாதுரை'],
            ['Rajaraja Chola', 'இராஜராஜ சோழன்'], ['Rajendra Chola', 'இராஜேந்திர சோழன்'],
            ['Karikalan', 'கரிகாலன்'], ['Ashoka', 'அசோகர்'], ['Akbar', 'அக்பர்'],
            ['Vallabhbhai Patel', 'வல்லபாய் படேல்'], ['Rajaji', 'ராஜாஜி'],
            ['Abdul Kalam', 'அப்துல் கலாம்'], ['Vivekananda', 'விவேகானந்தர்'],
            ['Raja Ram Mohan Roy', 'ராஜா ராம்மோகன் ராய்'], ['Rabindranath Tagore', 'இரவீந்திரநாத் தாகூர்'],
            ['Muthulakshmi Reddy', 'முத்துலட்சுமி ரெட்டி'], ['Vallalar', 'வள்ளலார்'],
        ],
        'dynasty' => [
            // Whole nouns, never bare stems: சேர alone is the start of
            // சேர்மம் and சேர்ந்தவர், and would be matched inside them.
            ['Cholas', 'சோழர்கள்'], ['Chola', 'சோழர்'], ['Pandyas', 'பாண்டியர்கள்'], ['Pandya', 'பாண்டியர்'],
            ['Cheras', 'சேரர்கள்'], ['Chera', 'சேரர்'], ['Pallavas', 'பல்லவர்கள்'], ['Pallava', 'பல்லவர்'],
        ],
        'literature' => [
            ['Thirukkural', 'திருக்குறள்'], ['Silappathikaram', 'சிலப்பதிகாரம்'],
            ['Manimekalai', 'மணிமேகலை'], ['Tolkappiyam', 'தொல்காப்பியம்'],
        ],
        'organisation' => [
            ['Indian National Congress', 'இந்திய தேசிய காங்கிரஸ்'], ['Justice Party', 'நீதிக்கட்சி'],
            ['East India Company', 'கிழக்கிந்திய கம்பெனி'], ['ISRO', 'இஸ்ரோ'],
            ['United Nations', 'ஐக்கிய நாடுகள் சபை'], ['Reserve Bank of India', 'இந்திய ரிசர்வ் வங்கி'],
            ['UNESCO', 'யுனெஸ்கோ'], ['NITI Aayog', 'நிதி ஆயோக்'],
        ],
    ];

    public function run(): void
    {
        $english = Language::byCode('en');
        $tamil = Language::byCode('ta');

        if ($english === null || $tamil === null) {
            return;
        }

        // Existing keys loaded once, mirroring the unique index, so a re-seed
        // is one read plus inserts for whatever is genuinely new.
        $existing = [];

        foreach (GlossaryTerm::query()
            ->where('source_language_id', $english->getKey())
            ->where('target_language_id', $tamil->getKey())
            ->get(['source_term', 'domain']) as $row) {
            $existing[mb_strtolower($row->source_term).'|'.($row->domain ?? '')] = true;
        }

        foreach (['term' => self::TERMS, 'name' => self::NAMES] as $kind => $groups) {
            foreach ($groups as $domain => $pairs) {
                foreach ($pairs as [$source, $target]) {
                    $key = mb_strtolower($source).'|'.$domain;

                    if (isset($existing[$key])) {
                        continue;
                    }

                    GlossaryTerm::query()->create([
                        'source_language_id' => $english->getKey(),
                        'target_language_id' => $tamil->getKey(),
                        'kind' => $kind,
                        'domain' => $domain,
                        'source_term' => $source,
                        'target_term' => $target,
                        'is_active' => true,
                        'notes' => 'Seeded starter entry; awaiting native-reviewer verification.',
                    ]);

                    $existing[$key] = true;
                }
            }
        }
    }

    /** Number of entries this seeder defines, by kind. */
    public static function counts(): array
    {
        $count = static fn (array $groups): int => array_sum(array_map('count', $groups));

        return ['term' => $count(self::TERMS), 'name' => $count(self::NAMES)];
    }
}
