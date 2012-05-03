/*
AMtoMQlite.js
(c) 2012 David Lippman

Converts AsciiMath in MathQuill's version of TeX

Based on ASCIIMathML, Version 1.4.7 Aug 30, 2005, (c) Peter Jipsen http://www.chapman.edu/~jipsen
Modified with TeX conversion for IMG rendering Sept 6, 2006 (c) David Lippman http://www.pierce.ctc.edu/dlippman
Licensed under GNU General Public License (at http://www.gnu.org/copyleft/gpl.html) 
*/
var AMtoMQ = function(){
var decimalsign = '.';

var CONST = 0, UNARY = 1, BINARY = 2, INFIX = 3, LEFTBRACKET = 4, 
    RIGHTBRACKET = 5, SPACE = 6, UNDEROVER = 7, DEFINITION = 8,
    LEFTRIGHT = 9, TEXT = 10; // token types

var AMQsqrt = {input:"sqrt", tag:"msqrt", output:"sqrt", tex:null, ttype:UNARY},
  AMQroot  = {input:"root", tag:"mroot", output:"root", tex:null, ttype:BINARY},
  AMQfrac  = {input:"frac", tag:"mfrac", output:"/",    tex:null, ttype:BINARY},
  AMQdiv   = {input:"/",    tag:"mfrac", output:"/",    tex:null, ttype:INFIX},
  AMQover  = {input:"stackrel", tag:"mover", output:"stackrel", tex:null, ttype:BINARY},
  AMQsub   = {input:"_",    tag:"msub",  output:"_",    tex:null, ttype:INFIX},
  AMQsup   = {input:"^",    tag:"msup",  output:"^",    tex:null, ttype:INFIX},
  AMQtext  = {input:"text", tag:"mtext", output:"text", tex:null, ttype:TEXT},
  AMQmbox  = {input:"mbox", tag:"mtext", output:"mbox", tex:null, ttype:TEXT},
  AMQquote = {input:"\"",   tag:"mtext", output:"mbox", tex:null, ttype:TEXT};

var AMQsymbols = [
//some greek symbols
{input:"alpha",  tag:"mi", output:"\u03B1", tex:null, ttype:CONST},
{input:"beta",   tag:"mi", output:"\u03B2", tex:null, ttype:CONST},
{input:"chi",    tag:"mi", output:"\u03C7", tex:null, ttype:CONST},
{input:"delta",  tag:"mi", output:"\u03B4", tex:null, ttype:CONST},
{input:"Delta",  tag:"mo", output:"\u0394", tex:null, ttype:CONST},
{input:"epsi",   tag:"mi", output:"\u03B5", tex:"epsilon", ttype:CONST},
{input:"varepsilon", tag:"mi", output:"\u025B", tex:null, ttype:CONST},
{input:"eta",    tag:"mi", output:"\u03B7", tex:null, ttype:CONST},
{input:"gamma",  tag:"mi", output:"\u03B3", tex:null, ttype:CONST},
{input:"Gamma",  tag:"mo", output:"\u0393", tex:null, ttype:CONST},
{input:"iota",   tag:"mi", output:"\u03B9", tex:null, ttype:CONST},
{input:"kappa",  tag:"mi", output:"\u03BA", tex:null, ttype:CONST},
{input:"lambda", tag:"mi", output:"\u03BB", tex:null, ttype:CONST},
{input:"Lambda", tag:"mo", output:"\u039B", tex:null, ttype:CONST},
{input:"mu",     tag:"mi", output:"\u03BC", tex:null, ttype:CONST},
{input:"nu",     tag:"mi", output:"\u03BD", tex:null, ttype:CONST},
{input:"omega",  tag:"mi", output:"\u03C9", tex:null, ttype:CONST},
{input:"Omega",  tag:"mo", output:"\u03A9", tex:null, ttype:CONST},
{input:"phi",    tag:"mi", output:"\u03C6", tex:null, ttype:CONST},
{input:"varphi", tag:"mi", output:"\u03D5", tex:null, ttype:CONST},
{input:"Phi",    tag:"mo", output:"\u03A6", tex:null, ttype:CONST},
{input:"pi",     tag:"mi", output:"\u03C0", tex:null, ttype:CONST},
{input:"Pi",     tag:"mo", output:"\u03A0", tex:null, ttype:CONST},
{input:"psi",    tag:"mi", output:"\u03C8", tex:null, ttype:CONST},
{input:"Psi",    tag:"mi", output:"\u03A8", tex:null, ttype:CONST},
{input:"rho",    tag:"mi", output:"\u03C1", tex:null, ttype:CONST},
{input:"sigma",  tag:"mi", output:"\u03C3", tex:null, ttype:CONST},
{input:"Sigma",  tag:"mo", output:"\u03A3", tex:null, ttype:CONST},
{input:"tau",    tag:"mi", output:"\u03C4", tex:null, ttype:CONST},
{input:"theta",  tag:"mi", output:"\u03B8", tex:null, ttype:CONST},
{input:"vartheta", tag:"mi", output:"\u03D1", tex:null, ttype:CONST},
{input:"Theta",  tag:"mo", output:"\u0398", tex:null, ttype:CONST},
{input:"upsilon", tag:"mi", output:"\u03C5", tex:null, ttype:CONST},
{input:"xi",     tag:"mi", output:"\u03BE", tex:null, ttype:CONST},
{input:"Xi",     tag:"mo", output:"\u039E", tex:null, ttype:CONST},
{input:"zeta",   tag:"mi", output:"\u03B6", tex:null, ttype:CONST},

//binary operation symbols
{input:"*",  tag:"mo", output:"\u22C5", tex:"cdot", ttype:CONST},
{input:"xx", tag:"mo", output:"\u00D7", tex:"times", ttype:CONST},
{input:"-:", tag:"mo", output:"\u00F7", tex:"div", ttype:CONST},
//{input:"^^",  tag:"mo", output:"\u2227", tex:"wedge", ttype:CONST},
//{input:"^^^", tag:"mo", output:"\u22C0", tex:"bigwedge", ttype:UNDEROVER},
//{input:"vv",  tag:"mo", output:"\u2228", tex:"vee", ttype:CONST},
//{input:"vvv", tag:"mo", output:"\u22C1", tex:"bigvee", ttype:UNDEROVER},
//{input:"nn",  tag:"mo", output:"\u2229", tex:"cap", ttype:CONST},
//{input:"nnn", tag:"mo", output:"\u22C2", tex:"bigcap", ttype:UNDEROVER},
{input:"uu",  tag:"mo", output:"\u222A", tex:"cup", ttype:CONST},
{input:"U",  tag:"mo", output:"\u222A", tex:"cup", ttype:CONST},
//{input:"uuu", tag:"mo", output:"\u22C3", tex:"bigcup", ttype:UNDEROVER},

//binary relation symbols
{input:"!=",  tag:"mo", output:"\u2260", tex:"ne", ttype:CONST},
//{input:":=",  tag:"mo", output:":=",     tex:null, ttype:CONST},
{input:"lt",  tag:"mo", output:"<",      tex:null, ttype:CONST},
{input:"gt",  tag:"mo", output:">",      tex:null, ttype:CONST},
{input:"<=",  tag:"mo", output:"\u2264", tex:"le", ttype:CONST},
{input:"lt=", tag:"mo", output:"\u2264", tex:"leq", ttype:CONST},
{input:"gt=",  tag:"mo", output:"\u2265", tex:"geq", ttype:CONST},
{input:">=",  tag:"mo", output:"\u2265", tex:"ge", ttype:CONST},
{input:"geq", tag:"mo", output:"\u2265", tex:null, ttype:CONST},
//{input:"-<",  tag:"mo", output:"\u227A", tex:"prec", ttype:CONST},
//{input:"-lt", tag:"mo", output:"\u227A", tex:null, ttype:CONST},
//{input:">-",  tag:"mo", output:"\u227B", tex:"succ", ttype:CONST},
//{input:"-<=", tag:"mo", output:"\u2AAF", tex:"preceq", ttype:CONST},
//{input:">-=", tag:"mo", output:"\u2AB0", tex:"succeq", ttype:CONST},
//{input:"in",  tag:"mo", output:"\u2208", tex:null, ttype:CONST},
//{input:"!in", tag:"mo", output:"\u2209", tex:"notin", ttype:CONST},
//{input:"sub", tag:"mo", output:"\u2282", tex:"subset", ttype:CONST},
//{input:"sup", tag:"mo", output:"\u2283", tex:"supset", ttype:CONST},
//{input:"sube", tag:"mo", output:"\u2286", tex:"subseteq", ttype:CONST},
//{input:"supe", tag:"mo", output:"\u2287", tex:"supseteq", ttype:CONST},

//grouping brackets
{input:"(", tag:"mo", output:"(", tex:null, ttype:LEFTBRACKET},
{input:")", tag:"mo", output:")", tex:null, ttype:RIGHTBRACKET},
{input:"[", tag:"mo", output:"[", tex:null, ttype:LEFTBRACKET},
{input:"]", tag:"mo", output:"]", tex:null, ttype:RIGHTBRACKET},
{input:"{", tag:"mo", output:"{", tex:"lbrace", ttype:LEFTBRACKET},
{input:"}", tag:"mo", output:"}", tex:"rbrace", ttype:RIGHTBRACKET},
{input:"|", tag:"mo", output:"|", tex:null, ttype:LEFTRIGHT},
//{input:"||", tag:"mo", output:"||", tex:null, ttype:LEFTRIGHT},
{input:"(:", tag:"mo", output:"\u2329", tex:"langle", ttype:LEFTBRACKET},
{input:":)", tag:"mo", output:"\u232A", tex:"rangle", ttype:RIGHTBRACKET},
{input:"<<", tag:"mo", output:"\u2329", tex:"langle", ttype:LEFTBRACKET},
{input:">>", tag:"mo", output:"\u232A", tex:"rangle", ttype:RIGHTBRACKET},
//{input:"{:", tag:"mo", output:"{:", tex:null, ttype:LEFTBRACKET, invisible:true},
//{input:":}", tag:"mo", output:":}", tex:null, ttype:RIGHTBRACKET, invisible:true},

//miscellaneous symbols
{input:"+-",   tag:"mo", output:"\u00B1", tex:"pm", ttype:CONST},
{input:"O/",   tag:"mo", output:"\u2205", tex:"emptyset", ttype:CONST},
{input:"oo",   tag:"mo", output:"\u221E", tex:"infty", ttype:CONST},
//{input:"CC",  tag:"mo", output:"\u2102", tex:"mathbb{C}", ttype:CONST, notexcopy:true},
//{input:"NN",  tag:"mo", output:"\u2115", tex:"mathbb{N}", ttype:CONST, notexcopy:true},
//{input:"QQ",  tag:"mo", output:"\u211A", tex:"mathbb{Q}", ttype:CONST, notexcopy:true},
{input:"RR",  tag:"mo", output:"\u211D", tex:"mathbb{R}", ttype:CONST, notexcopy:true},
//{input:"ZZ",  tag:"mo", output:"\u2124", tex:"mathbb{Z}", ttype:CONST, notexcopy:true},
{input:"f",   tag:"mi", output:"f",      tex:null, ttype:UNARY, func:true, val:true},
//{input:"g",   tag:"mi", output:"g",      tex:null, ttype:UNARY, func:true, val:true},
//{input:"''", tag:"mo", output:"''", tex:null, val:true},
//{input:"'''", tag:"mo", output:"'''", tex:null, val:true},
//{input:"''''", tag:"mo", output:"''''", tex:null, val:true},


//standard functions
{input:"sin",  tag:"mo", output:"sin", tex:null, ttype:UNARY, func:true},
{input:"cos",  tag:"mo", output:"cos", tex:null, ttype:UNARY, func:true},
{input:"tan",  tag:"mo", output:"tan", tex:null, ttype:UNARY, func:true},
{input:"arcsin",  tag:"mo", output:"arcsin", tex:null, ttype:UNARY, func:true},
{input:"arccos",  tag:"mo", output:"arccos", tex:null, ttype:UNARY, func:true},
{input:"arctan",  tag:"mo", output:"arctan", tex:null, ttype:UNARY, func:true},
{input:"sinh", tag:"mo", output:"sinh", tex:null, ttype:UNARY, func:true},
{input:"cosh", tag:"mo", output:"cosh", tex:null, ttype:UNARY, func:true},
{input:"tanh", tag:"mo", output:"tanh", tex:null, ttype:UNARY, func:true},
{input:"cot",  tag:"mo", output:"cot", tex:null, ttype:UNARY, func:true},
{input:"coth",  tag:"mo", output:"coth", tex:null, ttype:UNARY, func:true},
{input:"sech",  tag:"mo", output:"sech", tex:null, ttype:UNARY, func:true},
{input:"csch",  tag:"mo", output:"csch", tex:null, ttype:UNARY, func:true},
{input:"sec",  tag:"mo", output:"sec", tex:null, ttype:UNARY, func:true},
{input:"csc",  tag:"mo", output:"csc", tex:null, ttype:UNARY, func:true},
{input:"log",  tag:"mo", output:"log", tex:null, ttype:UNARY, func:true},
{input:"ln",   tag:"mo", output:"ln",  tex:null, ttype:UNARY, func:true},
{input:"abs",   tag:"mo", output:"abs",  tex:null, ttype:UNARY, func:true},

{input:"Sin",  tag:"mo", output:"sin", tex:null, ttype:UNARY, func:true},
{input:"Cos",  tag:"mo", output:"cos", tex:null, ttype:UNARY, func:true},
{input:"Tan",  tag:"mo", output:"tan", tex:null, ttype:UNARY, func:true},
{input:"Arcsin",  tag:"mo", output:"arcsin", tex:null, ttype:UNARY, func:true},
{input:"Arccos",  tag:"mo", output:"arccos", tex:null, ttype:UNARY, func:true},
{input:"Arctan",  tag:"mo", output:"arctan", tex:null, ttype:UNARY, func:true},
{input:"Sinh", tag:"mo", output:"sinh", tex:null, ttype:UNARY, func:true},
{input:"Sosh", tag:"mo", output:"cosh", tex:null, ttype:UNARY, func:true},
{input:"Tanh", tag:"mo", output:"tanh", tex:null, ttype:UNARY, func:true},
{input:"Cot",  tag:"mo", output:"cot", tex:null, ttype:UNARY, func:true},
{input:"Sec",  tag:"mo", output:"sec", tex:null, ttype:UNARY, func:true},
{input:"Csc",  tag:"mo", output:"csc", tex:null, ttype:UNARY, func:true},
{input:"Log",  tag:"mo", output:"log", tex:null, ttype:UNARY, func:true},
{input:"Ln",   tag:"mo", output:"ln",  tex:null, ttype:UNARY, func:true},
{input:"Abs",   tag:"mo", output:"abs",  tex:null, ttype:UNARY, func:true},

//commands with argument
AMQsqrt, AMQroot, AMQfrac, AMQdiv, AMQover, AMQsub, AMQsup,
{input:"Sqrt", tag:"msqrt", output:"sqrt", tex:null, ttype:UNARY},
//{input:"hat", tag:"mover", output:"\u005E", tex:null, ttype:UNARY, acc:true},
//{input:"bar", tag:"mover", output:"\u00AF", tex:"overline", ttype:UNARY, acc:true},
//{input:"vec", tag:"mover", output:"\u2192", tex:null, ttype:UNARY, acc:true},
//{input:"tilde", tag:"mover", output:"~", tex:null, ttype:UNARY, acc:true}, 
//{input:"dot", tag:"mover", output:".",      tex:null, ttype:UNARY, acc:true},
//{input:"ddot", tag:"mover", output:"..",    tex:null, ttype:UNARY, acc:true},
//{input:"ul", tag:"munder", output:"\u0332", tex:"underline", ttype:UNARY, acc:true},
AMQtext, AMQmbox, AMQquote
//{input:"var", tag:"mstyle", atname:"fontstyle", atval:"italic", output:"var", tex:null, ttype:UNARY},
//{input:"color", tag:"mstyle", ttype:BINARY}
];

function compareNames(s1,s2) {
  if (s1.input > s2.input) return 1
  else return -1;
}

var AMQnames = []; //list of input symbols

function AMQinitSymbols() {
  var texsymbols = [], i;
  for (i=0; i<AMQsymbols.length; i++) {
	  if (typeof AMQsymbols[i].tex !='undefined' && AMQsymbols[i].tex != null && !(typeof AMQsymbols[i].notexcopy == "boolean" && AMQsymbols[i].notexcopy)) { 
		  texsymbols[texsymbols.length] = {input:AMQsymbols[i].tex, 
		  tag:AMQsymbols[i].tag, output:AMQsymbols[i].output, ttype:AMQsymbols[i].ttype};
	  }
  }
  AMQsymbols = AMQsymbols.concat(texsymbols);
  AMQsymbols.sort(compareNames);
  for (i=0; i<AMQsymbols.length; i++) AMQnames[i] = AMQsymbols[i].input;
  
}

function AMQremoveCharsAndBlanks(str,n) {
//remove n characters and any following blanks
  var st;
  if (str.charAt(n)=="\\" && str.charAt(n+1)!="\\" && str.charAt(n+1)!=" ") 
    st = str.slice(n+1);
  else st = str.slice(n);
  for (var i=0; i<st.length && st.charCodeAt(i)<=32; i=i+1);
  return st.slice(i);
}

function AMQposition(arr, str, n) { 
// return position >=n where str appears or would be inserted
// assumes arr is sorted
  if (n==0) {
    var h,m;
    n = -1;
    h = arr.length;
    while (n+1<h) {
      m = (n+h) >> 1;
      if (arr[m]<str) n = m; else h = m;
    }
    return h;
  } else
    for (var i=n; i<arr.length && arr[i]<str; i++);
  return i; // i=arr.length || arr[i]>=str
}

function AMQgetSymbol(str) {
//return maximal initial substring of str that appears in names
//return null if there is none
  var k = 0; //new pos
  var j = 0; //old pos
  var mk; //match pos
  var st;
  var tagst;
  var match = "";
  var more = true;
  for (var i=1; i<=str.length && more; i++) {
    st = str.slice(0,i); //initial substring of length i
    j = k;
    k = AMQposition(AMQnames, st, j);
    if (k<AMQnames.length && str.slice(0,AMQnames[k].length)==AMQnames[k]){
      match = AMQnames[k];
      mk = k;
      i = match.length;
    }
    more = k<AMQnames.length && str.slice(0,AMQnames[k].length)>=AMQnames[k];
  }
  AMQpreviousSymbol=AMQcurrentSymbol;
  if (match!=""){
    AMQcurrentSymbol=AMQsymbols[mk].ttype;
    return AMQsymbols[mk]; 
  }
// if str[0] is a digit or - return maxsubstring of digits.digits
  AMQcurrentSymbol=CONST;
  k = 1;
  st = str.slice(0,1);
  var integ = true;
 	  
  while ("0"<=st && st<="9" && k<=str.length) {
    st = str.slice(k,k+1);
    k++;
  }
  if (st == decimalsign) {
    st = str.slice(k,k+1);
    if ("0"<=st && st<="9") {
      integ = false;
      k++;
      while ("0"<=st && st<="9" && k<=str.length) {
        st = str.slice(k,k+1);
        k++;
      }
    }
  }
  if ((integ && k>1) || k>2) {
    st = str.slice(0,k-1);
    tagst = "mn";
  } else {
    k = 2;
    st = str.slice(0,1); //take 1 character
    tagst = (("A">st || st>"Z") && ("a">st || st>"z")?"mo":"mi");
  }
  if (st=="-" && AMQpreviousSymbol==INFIX) {
    AMQcurrentSymbol = INFIX;
    return {input:st, tag:tagst, output:st, ttype:UNARY, func:true, val:true};
  }
  return {input:st, tag:tagst, output:st, ttype:CONST, val:true}; //added val bit
}

function AMQTremoveBrackets(node) {
	
  var st;
  //if (node.charAt(0)=='{' && node.charAt(node.length-1)=='}') {
    node = trim12(node);
    st = node.charAt(0);
    if (st=="(" || st=="[") node = node.substr(1);
    st = node.substr(0,6);
    if (st=="\\left(" || st=="\\left[" || st=="\\left{") node = node.substr(6);
    st = node.substr(0,12);
    if (st=="\\left\\lbrace" || st=="\\left\\langle") node = node.substr(12);
    st = node.charAt(node.length-1);
   // alert("lastchar: "+st+" from "+node);
    if (st==")" || st=="]" || st=='.') node = node.substr(0,node.length-7);
    st = node.substr(node.length-7,7)
    if (st=="\\rbrace" || st=="\\rangle") node = node.substr(0,node.length-13);
    
  //} 
  return node;
}

function trim12 (str) {
	var	str = str.replace(/^\s\s*/, ''),
		ws = /\s/,
		i = str.length;
	while (ws.test(str.charAt(--i)));
	return str.slice(0, i + 1);
}

/*Parsing ASCII math expressions with the following grammar
v ::= [A-Za-z] | greek letters | numbers | other constant symbols
u ::= sqrt | text | bb | other unary symbols for font commands
b ::= frac | root | stackrel         binary symbols
l ::= ( | [ | { | (: | {:            left brackets
r ::= ) | ] | } | :) | :}            right brackets
S ::= v | lEr | uS | bSS             Simple expression
I ::= S_S | S^S | S_S^S | S          Intermediate expression
E ::= IE | I/I                       Expression
Each terminal symbol is translated into a corresponding mathml node.*/

var AMQnestingDepth,AMQpreviousSymbol,AMQcurrentSymbol;

function AMQTgetTeXsymbol(symb) {
	if (typeof symb.val == "boolean" && symb.val) {
		pre = '';
	} else {
		pre = '\\';
	}
	if (symb.tex==null) {
		return (pre+(pre==''?symb.input:symb.input.toLowerCase()));
	} else {
		return (pre+symb.tex);
	}
}
function AMQTgetTeXbracket(symb) {
	if (symb.tex==null) {
		return (symb.input);
	} else {
		return ('\\'+symb.tex);
	}
}

function AMQTparseSexpr(str) { //parses str and returns [node,tailstr]
  var symbol, node, result, i, st,// rightvert = false,
    newFrag = '';
  str = AMQremoveCharsAndBlanks(str,0);
  symbol = AMQgetSymbol(str);             //either a token or a bracket or empty
  
  if (symbol == null || symbol.ttype == RIGHTBRACKET && AMQnestingDepth > 0) {
    return [null,str];
  }
  if (symbol.ttype == DEFINITION) {
    str = symbol.output+AMQremoveCharsAndBlanks(str,symbol.input.length); 
    symbol = AMQgetSymbol(str);
  }
  switch (symbol.ttype) {
  case UNDEROVER:
  case CONST:
    str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
     var texsymbol = AMQTgetTeXsymbol(symbol);
     if (texsymbol.charAt(0)=="\\" || symbol.tag=="mo") return [texsymbol,str];
     else return [texsymbol,str];
    
  case LEFTBRACKET:   //read (expr+)
    AMQnestingDepth++;
    str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
   
    result = AMQTparseExpr(str,true);
    AMQnestingDepth--;
    if (result[0].substr(0,6)=="\\right") {
	    if (result[0].substr(0,7)=="\\right.") {
		    result[0] = result[0].substr(7);
	    } else {
		    result[0] = result[0].substr(6);
	    }
	    if (typeof symbol.invisible == "boolean" && symbol.invisible) 
		    node = result[0];
	    else {
		    node = AMQTgetTeXbracket(symbol) + result[0];
	    }    
    } else {
	    if (typeof symbol.invisible == "boolean" && symbol.invisible) 
		    node = '\\left.'+result[0];
	    else {
		    if (symbol.input=='(' && result[0].substr(result[0].length-1)==']') {
	    	    	    node = '\\intervalopenleft{'+result[0].substr(0,result[0].length-7)+'}';
	    	    } else if (symbol.input=='[' && result[0].substr(result[0].length-1)==')') {
	    	    	    node = '\\intervalopenright{'+result[0].substr(0,result[0].length-7)+'}';
	    	    } else if (symbol.input=='<<' && result[0].substr(result[0].length-6)=='rangle') {
	    	    	    node = '\\langle{'+result[0].substr(0,result[0].length-7-6)+'}';
	    	    } else {
	    	    	    node = '\\left'+AMQTgetTeXbracket(symbol) + result[0];
	    	    }
	    }
    }
    return [node,result[1]];
  case TEXT:
      if (symbol!=AMQquote) str = AMQremoveCharsAndBlanks(str,symbol.input.length);
      if (str.charAt(0)=="{") i=str.indexOf("}");
      else if (str.charAt(0)=="(") i=str.indexOf(")");
      else if (str.charAt(0)=="[") i=str.indexOf("]");
      else if (symbol==AMQquote) i=str.slice(1).indexOf("\"")+1;
      else i = 0;
      if (i==-1) i = str.length;
      st = str.slice(1,i);
      //if (st.charAt(0) == " ") {
	//      newFrag = '\\ ';
      //}
      newFrag += '\\text{'+st+'}';
     // if (st.charAt(st.length-1) == " ") {
//	      newFrag += '\\ ';
  //    }
      str = AMQremoveCharsAndBlanks(str,i+1);
      return [newFrag,str];
  case UNARY:
      str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
      result = AMQTparseSexpr(str);
     
      if (result[0]==null) return [AMQTgetTeXsymbol(symbol),str];
     
      if (typeof symbol.func == "boolean" && symbol.func) { // functions hack
        st = str.charAt(0);
        if (st=="^" || st=="_" || st=="/" || st=="|" || st==",") {
          return [AMQTgetTeXsymbol(symbol),str];
        } else {
        	if (symbol.input=='-') {
        		node =  '-'+result[0]; 
        	} else {
        		if (symbol.input=='abs') {
        			node = '\\left|'+AMQTremoveBrackets(result[0])+'\\right|';
        		} else {
        			node = AMQTgetTeXsymbol(symbol)+((st=='(')?"":" ")+result[0];
        		}
        	}
		return [node,result[1]];
        }
      }
      
      result[0] = AMQTremoveBrackets(result[0]);
     
      if (symbol.input == "sqrt") {           // sqrt
	      return ['\\sqrt{'+result[0]+'}',result[1]];
      } else if (typeof symbol.acc == "boolean" && symbol.acc) {   // accent
	      return [AMQTgetTeXsymbol(symbol)+'{'+result[0]+'}',result[1]];
      } else {                        // font change command  
	    return [AMQTgetTeXsymbol(symbol)+'{'+result[0]+'}',result[1]];
      }
  case BINARY:
    str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
    result = AMQTparseSexpr(str);
    if (result[0]==null) return [AMQTgetTeXsymbol(symbol),str];
    result[0] = AMQTremoveBrackets(result[0]);
    
    var result2 = AMQTparseSexpr(result[1]);
    if (result2[0]==null) return [AMQTgetTeXsymbol(symbol),str];
    result2[0] = AMQTremoveBrackets(result2[0]);
    if (symbol.input=="color") {
    	newFrag = '{\\color{'+result[0].replace(/[\{\}]/g,'')+'}'+result2[0]+'}}';    
    }
    if (symbol.input=="root" || symbol.input=="stackrel") {
	    if (symbol.input=="root") {
		    newFrag = '\\nthroot{'+result[0]+'}{'+result2[0]+'}';
	    } else {
		    newFrag = AMQTgetTeXsymbol(symbol)+'{'+result[0]+'}{'+result2[0]+'}';
	    }
    }
    if (symbol.input=="frac") {
	    newFrag = '\\frac{'+result[0]+'}{'+result2[0]+'}';
    }
    return [newFrag,result2[1]];
  case INFIX:
    str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
    return [symbol.output,str];
  case SPACE:
    str = AMQremoveCharsAndBlanks(str,symbol.input.length);
    return ['\\quad\\text{'+symbol.input+'}\\quad',str];
  case LEFTRIGHT:
//    if (rightvert) return [null,str]; else rightvert = true;
    AMQnestingDepth++;
    str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
    result = AMQTparseExpr(str,false);
    AMQnestingDepth--;
    var st = "";
    st = result[0].charAt(result[0].length -1);
//alert(result[0].lastChild+"***"+st);
    if (st == "|") { // its an absolute value subterm
	    node = '\\left|'+result[0];
      return [node,result[1]];
    } else { // the "|" is a \mid
      node = '\\mid';
      return [node,str];
    }
   
  default:
//alert("default");
    str = AMQremoveCharsAndBlanks(str,symbol.input.length); 
    return [AMQTgetTeXsymbol(symbol),str];
  }
}

function AMQTparseIexpr(str) {
  var symbol, sym1, sym2, node, result, underover;
  str = AMQremoveCharsAndBlanks(str,0);
  sym1 = AMQgetSymbol(str);
  result = AMQTparseSexpr(str);
  node = result[0];
  str = result[1];
  symbol = AMQgetSymbol(str);
  if (symbol.ttype == INFIX && symbol.input != "/") {
    str = AMQremoveCharsAndBlanks(str,symbol.input.length);
   // if (symbol.input == "/") result = AMQTparseIexpr(str); else 

    result = AMQTparseSexpr(str);
    
    if (result[0] == null) // show box in place of missing argument
	    result[0] = '{}';
    else result[0] = AMQTremoveBrackets(result[0]);
    str = result[1];
//    if (symbol.input == "/") AMQTremoveBrackets(node);
    if (symbol.input == "_") {
      sym2 = AMQgetSymbol(str);
      underover = (sym1.ttype == UNDEROVER);
      if (sym2.input == "^") {
        str = AMQremoveCharsAndBlanks(str,sym2.input.length);
        var res2 = AMQTparseSexpr(str);
        res2[0] = AMQTremoveBrackets(res2[0]);
        str = res2[1];
        node = '{' + node;
       	node += '_{'+result[0]+'}';
	node += '^{'+res2[0]+'}';
        node += '}';
      } else {
        node += '_{'+result[0]+'}';
      }
    } else { //must be ^
    	   // if (node.match(/^\w+$/)) {
    	    	    node = node + '^{'+result[0]+'}';
    	   // } else {
    	    //	    node = '{'+node+'}^{'+result[0]+'}';
    	   // }
    }
  } 
  
  return [node+' ',str];
}

function AMQTparseExpr(str,rightbracket) {
  var symbol, node, result, i, nodeList = [],
  newFrag = '';
  var addedright = false;
  do {
    str = AMQremoveCharsAndBlanks(str,0);
    result = AMQTparseIexpr(str);
    node = result[0];
    str = result[1];
    symbol = AMQgetSymbol(str);
    if (symbol.ttype == INFIX && symbol.input == "/") {
      str = AMQremoveCharsAndBlanks(str,symbol.input.length);
      result = AMQTparseIexpr(str);
      
      if (result[0] == null) // show box in place of missing argument
	      result[0] = '{}';
      else result[0] = AMQTremoveBrackets(result[0]);
      str = result[1];
      node = AMQTremoveBrackets(node);
      node = '\\frac' + '{'+ node + '}';
      node += '{'+result[0]+'}';
      newFrag += node;
      symbol = AMQgetSymbol(str);
    }  else if (node!=undefined) newFrag += node;
  } while ((symbol.ttype != RIGHTBRACKET && 
           (symbol.ttype != LEFTRIGHT || rightbracket)
           || AMQnestingDepth == 0) && symbol!=null && symbol.output!="");
  if (symbol.ttype == RIGHTBRACKET || symbol.ttype == LEFTRIGHT) {
//    if (AMQnestingDepth > 0) AMQnestingDepth--;
	var len = newFrag.length;
	if (len>2 && newFrag.charAt(0)=='{' && newFrag.indexOf(',')>0) { //could be matrix (total rewrite from .js)
		var right = newFrag.charAt(len - 2);
		if (right==')' || right==']') {
			var left = newFrag.charAt(6);
			if ((left=='(' && right==')' && symbol.output != '}') || (left=='[' && right==']')) {
				var mxout = '\\matrix{';
				var pos = new Array(); //position of commas
				pos.push(0);
				var matrix = true;
				var mxnestingd = 0;
				var subpos = [];
				subpos[0] = [0];
				var lastsubposstart = 0;
				var mxanynestingd = 0;
				for (i=1; i<len-1; i++) {
					if (newFrag.charAt(i)==left) mxnestingd++;
					if (newFrag.charAt(i)==right) {
						mxnestingd--;
						if (mxnestingd==0 && newFrag.charAt(i+2)==',' && newFrag.charAt(i+3)=='{') {
							pos.push(i+2);
							lastsubposstart = i+2;
							subpos[lastsubposstart] = [i+2];
						}
					}
					if (newFrag.charAt(i)=='[' || newFrag.charAt(i)=='(') { mxanynestingd++;}
					if (newFrag.charAt(i)==']' || newFrag.charAt(i)==')') { mxanynestingd--;}
					if (newFrag.charAt(i)==',' && mxanynestingd==1) {
						subpos[lastsubposstart].push(i);
					}
				}
				pos.push(len);
				var lastmxsubcnt = -1;
				if (mxnestingd==0 && pos.length>0) {
					for (i=0;i<pos.length-1;i++) {
						if (i>0) mxout += '\\\\';
						if (i==0) {
							//var subarr = newFrag.substr(pos[i]+7,pos[i+1]-pos[i]-15).split(',');
							if (subpos[pos[i]].length==1) {
								var subarr = [newFrag.substr(pos[i]+7,pos[i+1]-pos[i]-15)];
							} else {
								var subarr = [newFrag.substring(pos[i]+7,subpos[pos[i]][1])];
								for (var j=2;j<subpos[pos[i]].length;j++) {
									subarr.push(newFrag.substring(subpos[pos[i]][j-1]+1,subpos[pos[i]][j]));
								}
								subarr.push(newFrag.substring(subpos[pos[i]][subpos[pos[i]].length-1]+1,pos[i+1]-8));
							}
						} else {
							//var subarr = newFrag.substr(pos[i]+8,pos[i+1]-pos[i]-16).split(',');
							if (subpos[pos[i]].length==1) {
								var subarr = [newFrag.substr(pos[i]+8,pos[i+1]-pos[i]-16)];
							} else {
								var subarr = [newFrag.substring(pos[i]+8,subpos[pos[i]][1])];
								for (var j=2;j<subpos[pos[i]].length;j++) {
									subarr.push(newFrag.substring(subpos[pos[i]][j-1]+1,subpos[pos[i]][j]));
								}
								subarr.push(newFrag.substring(subpos[pos[i]][subpos[pos[i]].length-1]+1,pos[i+1]-8));
							}
						}
						if (lastmxsubcnt>0 && subarr.length!=lastmxsubcnt) {
							matrix = false;
						} else if (lastmxsubcnt==-1) {
							lastmxsubcnt=subarr.length;
						}
						mxout += subarr.join('&');
					}
				}
				mxout += '}';
				if (matrix) { newFrag = mxout;}
			}
		}
	}
    
    str = AMQremoveCharsAndBlanks(str,symbol.input.length);
    if (typeof symbol.invisible != "boolean" || !symbol.invisible) {
      node = '\\right'+AMQTgetTeXbracket(symbol); //AMQcreateMmlNode("mo",document.createTextNode(symbol.output));
      newFrag += node;
      addedright = true;
    } else {
	    newFrag += '\\right.';
	    addedright = true;
    }
   
  }
  if(AMQnestingDepth>0 && !addedright) {
	  newFrag += '\\right.'; //adjust for non-matching left brackets
	  //todo: adjust for non-matching right brackets
  }
 
  return [newFrag,str];
}

AMQinitSymbols();

return function(str) {
 AMQnestingDepth = 0;
  str = str.replace(/(&nbsp;|\u00a0|&#160;)/g,"");
  str = str.replace(/&gt;/g,">");
  str = str.replace(/&lt;/g,"<");
  str = str.replace(/\s*\bor\b\s*/g,'" or "');
  str = str.replace(/all\s+real\s+numbers/g,'"all real numbers"');
  if (str.match(/\S/)==null) {
	  return "";
  }
  return AMQTparseExpr(str.replace(/^\s+/g,""),false)[0];
}
}();

/*
\left| expr \right| to  abs(expr)
\left( expression \right)  to   (expression)
\sqrt{expression}  to sqrt(expression)
\nthroot{n}{expr}  to  root(n)(expr)
\frac{num}{denom} to (num)/(denom)
\langle whatever \rangle   to   < whatever >                 **not done yet
\begin{matrix} a&b\\c&d  \end{matrix}    to  [(a,b),(c,d)]   **not done yet

n\frac{num}{denom} to n num/denom
*/
function MQtoAM(tex) {
	tex = tex.replace(/\\:/g,' ');
	while ((i=tex.indexOf('\\left|'))!=-1) { //found a left |
		nested = 0;
		do {
			lb = tex.indexOf('\\left|',i+1);
			rb = tex.indexOf('\\right|',i+1);
			if (lb !=-1 && lb < rb) {	//if another left |
				nested++;  
			} else if (rb!=-1 && (lb == -1 || rb < lb)) {  //if right |
				nested--;
			}
		} while (nested>0 && rb!=-1);  //until nested back to 0 or no right |
		if (rb!=-1) {  //have a right |  - replace with abs( )
			tex = tex.substring(0,rb) + ")" + tex.substring(rb+7);
			tex = tex.substring(0,i) + "abs(" + tex.substring(i+6);
		} else {
			tex = tex.substring(0,i) + "|" + tex.substring(i+6);
		}
	}
	tex = tex.replace(/\\text{\s*or\s*}/g,' or ');
	tex = tex.replace(/\\text{all\s+real\s+numbers}/g,'all real numbers');
	tex = tex.replace(/\\le(?!f)/g,'<=');
	tex = tex.replace(/\\ge/g,'>=');
	tex = tex.replace(/\\cup/g,'U');
	tex = tex.replace(/\\left/g,'');
	tex = tex.replace(/\\right/g,'');
	tex = tex.replace(/\\langle/g,'<');
	tex = tex.replace(/\\rangle/g,'>');
	tex = tex.replace(/\\cdot/g,'*');
	tex = tex.replace(/\\infty/g,'oo');
	tex = tex.replace(/\\nthroot/g,'root');
	tex = tex.replace(/\\varnothing/g,'DNE');
	tex = tex.replace(/\\/g,'');
	tex = tex.replace(/sqrt\[(.*?)\]/g,'root($1)');
	tex = tex.replace(/(\d)frac/g,'$1 frac');
	while ((i=tex.indexOf('frac{'))!=-1) { //found a fraction start
		nested = 1;
		curpos = i+5;
		while (nested>0 && curpos<tex.length-1) {
			curpos++;
			c = tex.charAt(curpos);
			if (c=='{') { nested++;}
			else if (c=='}') {nested--;}
		}
		if (nested==0) {
			tex = tex.substring(0,i)+"("+tex.substring(i+5,curpos)+")/"+tex.substring(curpos+1);
		} else {
			tex = tex.substring(0,i) + tex.substring(i+4);
		}
	}
	tex = tex.replace(/{/g,'(');
	tex = tex.replace(/}/g,')');
	tex = tex.replace(/\(([\d\.]+)\)\/\(([\d\.]+)\)/g,'$1/$2');  //change (2)/(3) to 2/3
	tex = tex.replace(/\/\(([\d\.]+)\)/g,'/$1');  //change /(3) to /3
	tex = tex.replace(/_{(\d+)}/g,'_$1');
	tex = tex.replace(/\^\(-1\)/g,'^-1');
	
	return tex;
}
